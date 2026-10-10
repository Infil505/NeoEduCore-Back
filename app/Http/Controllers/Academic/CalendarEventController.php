<?php

namespace App\Http\Controllers\Academic;

use App\Enums\UserType;
use App\Http\Controllers\Concerns\ExigeAutoria;
use App\Http\Controllers\Concerns\ResuelveAulasDestino;
use App\Http\Controllers\Controller;
use App\Models\Academic\CalendarEvent;
use App\Jobs\NotificarAviso;
use App\Models\Exams\Exam;
use App\Rules\FechaRazonable;
use App\Support\RelacionesEnLinea;
use App\Support\TenantCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Avisos del calendario.
 *
 * Son actividades **del docente** y llegan solo a los estudiantes de las aulas
 * que él elige (de las que el administrador le asignó). Un evento sin aula no
 * existe: el administrador tampoco los crea. Ver `ResuelveAulasDestino` y
 * `CalendarEvent::scopeVisibleTo()`.
 */
class CalendarEventController extends Controller
{
    use ExigeAutoria, ResuelveAulasDestino;

    /**
     * El alumno no necesita el correo del docente que puso el aviso.
     */
    private function acotarParaEstudiante(?object $user, iterable $eventos): void
    {
        if (!$user || $user->user_type !== UserType::Student) {
            return;
        }

        foreach ($eventos as $evento) {
            if ($evento->relationLoaded('creator') && $evento->creator !== null) {
                $evento->creator->setVisible(['id', 'full_name']);
            }
        }
    }

    /**
     * Listar eventos (con filtros). Cada rol ve lo suyo: ver
     * `CalendarEvent::scopeVisibleTo()`.
     * Filtros: event_type, group_id, exam_id, from, to
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'event_type' => ['nullable', Rule::in(['exam', 'activity', 'reminder', 'meeting'])],
            'group_id'   => ['nullable', 'uuid'],
            'exam_id'    => ['nullable', 'uuid'],
            'from'       => ['nullable', new FechaRazonable()],
            'to'         => ['bail', 'nullable', new FechaRazonable(), ...FechaRazonable::posteriorA($request, 'from')],
        ]);

        // Creador, aula y examen en la MISMA consulta (LEFT JOIN): con `with()` eran
        // tres viajes más a la base. El JSON es idéntico (ver `RelacionesEnLinea`).
        $query = RelacionesEnLinea::unir(CalendarEvent::query()->visibleTo($request->user()), [
            'creator' => ['users', 'created_by', ['id', 'full_name']],
            'group'   => ['groups', 'group_id', ['id', 'name', 'grade', 'section']],
            'exam'    => ['exams', 'exam_id', ['id', 'title']],
        ])->orderBy('calendar_events.start_at');
        $enLinea = [
            'creator' => \App\Models\Admin\User::class,
            'group'   => \App\Models\Academic\Group::class,
            'exam'    => \App\Models\Exams\Exam::class,
        ];

        if (!empty($data['event_type'])) {
            $query->where('event_type', $data['event_type']);
        }
        if (!empty($data['group_id'])) {
            $query->where('group_id', $data['group_id']);
        }
        if (!empty($data['exam_id'])) {
            $query->where('exam_id', $data['exam_id']);
        }

        // Rango por start_at/end_at
        if (!empty($data['from'])) {
            $query->where('end_at', '>=', $data['from']);
        }
        if (!empty($data['to'])) {
            $query->where('start_at', '<=', $data['to']);
        }

        $user = $request->user();

        // Lo que ve un alumno depende solo de sus aulas abiertas (más los avisos
        // generales) y de los filtros, así que la respuesta se comparte entre el
        // alumnado del mismo conjunto de aulas, igual que en `student-overview`
        // (misma área `AGENDA`, que se invalida al tocar avisos, exámenes o aulas).
        if ($user?->user_type === UserType::Student) {
            $aulas = DB::table('group_students')
                ->where('institution_id', $user->institution_id)
                ->where('student_user_id', $user->id)
                ->whereNull('left_at')
                ->pluck('group_id')->sort()->values();

            return response()->json(TenantCache::remember(
                $user->institution_id, TenantCache::AGENDA,
                'student-events:' . md5($aulas->implode(',')) . ':' . $this->huellaDeConsulta($request), 120,
                function () use ($query, $request, $user, $enLinea) {
                    $paginator = $this->paginar($query, $request);
                    RelacionesEnLinea::hidratar($paginator->getCollection(), $enLinea);
                    $this->acotarParaEstudiante($user, $paginator->getCollection());

                    return ['data' => $paginator->toArray()];
                }
            ));
        }

        $paginator = $this->paginar($query, $request);
        RelacionesEnLinea::hidratar($paginator->getCollection(), $enLinea);

        return response()->json([
            'data' => $paginator,
        ]);
    }

    /**
     * Crear evento (solo docentes: la ruta es `role:teacher`).
     *
     * El aviso se envía a una o varias aulas con `group_ids` (o `group_id` si es
     * una). Con una sola aula asignada se usa esa; con varias hay que elegir.
     *
     * Se crea **un evento por aula**, porque cada uno lleva su `group_id` y es
     * lo que decide quién lo ve. La respuesta es **siempre una lista** de eventos
     * (uno por aula), también con una sola aula: el cliente no tiene que
     * distinguir dos formas de respuesta.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'title'       => ['required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],

            'start_at' => ['required', new FechaRazonable()],
            'end_at'   => ['bail', 'required', new FechaRazonable(), ...FechaRazonable::posteriorA($request, 'start_at')],

            'event_type' => ['required', Rule::in(['exam', 'activity', 'reminder', 'meeting'])],

            'exam_id'  => ['nullable', 'uuid'],

            'group_id'    => ['nullable', 'uuid'],
            'group_ids'   => ['nullable', 'array'],
            'group_ids.*' => ['uuid', 'distinct'],

            // Solo el administrador: aviso para toda la institución, sin sección.
            'audience' => ['nullable', Rule::in(CalendarEvent::AUDIENCES)],
        ]);

        $user = $request->user();
        $esAdmin = $user->user_type === UserType::Admin;

        if (!empty($data['audience']) && !$esAdmin) {
            return response()->json(['message' => 'Solo el administrador publica avisos para toda la institución.'], 403);
        }

        if (!empty($data['exam_id']) && !$this->examenVisible($user, $data['exam_id'])) {
            return response()->json(['message' => 'Examen no encontrado'], 404);
        }

        $campos = fn (?string $grupoId, ?string $audience) => [
            'title' => trim($data['title']),
            'description' => $data['description'] ?? null,
            'start_at' => $data['start_at'],
            'end_at' => $data['end_at'],
            'event_type' => $data['event_type'],
            'exam_id' => $data['exam_id'] ?? null,
            'group_id' => $grupoId,
            'audience' => $audience,
            'created_by' => $user->id,
        ];

        // Aviso del centro: un solo evento, sin sección, para el público elegido.
        if (!empty($data['audience'])) {
            $evento = CalendarEvent::create($campos(null, $data['audience']))->load(['creator:id,full_name', 'group:id,name,grade,section', 'exam:id,title']);
            NotificarAviso::dispatch([$evento->id]);

            return response()->json(['data' => [$evento]], 201);
        }

        $pedidas = $data['group_ids'] ?? (isset($data['group_id']) ? [$data['group_id']] : null);

        // El administrador que no elige público tiene que elegir secciones.
        if ($esAdmin && empty($pedidas)) {
            return response()->json([
                'message' => 'Elige a quién va el aviso: estudiantes, docentes, todos o unas secciones.',
                'errors' => ['audience' => ['Elige los destinatarios del aviso.']],
            ], 422);
        }

        $grupos = $this->resolverAulasDestino($user, $pedidas);
        if ($grupos instanceof JsonResponse) {
            return $grupos;
        }

        $eventos = DB::transaction(fn () => array_map(
            fn (string $grupoId) => CalendarEvent::create($campos($grupoId, null))->load(['creator:id,full_name', 'group:id,name,grade,section', 'exam:id,title']),
            $grupos
        ));

        // Los estudiantes de esas secciones reciben el aviso en su campana, repartido
        // en segundo plano por el worker de la cola (la respuesta no espera).
        NotificarAviso::dispatch(array_map(fn ($e) => $e->id, $eventos));

        return response()->json([
            'data' => $eventos,
        ], 201);
    }

    /**
     * Ver evento. 404 si no es visible para quien pregunta.
     */
    public function show(Request $request, CalendarEvent $calendarEvent)
    {
        if (!CalendarEvent::query()->whereKey($calendarEvent->getKey())->visibleTo($request->user())->exists()) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        $calendarEvent->load(['creator:id,full_name', 'group:id,name,grade,section', 'exam:id,title']);
        $this->acotarParaEstudiante($request->user(), [$calendarEvent]);

        return response()->json([
            'data' => $calendarEvent,
        ]);
    }

    /**
     * Actualizar evento
     */
    public function update(Request $request, CalendarEvent $calendarEvent)
    {
        // S6: el evento es de quien lo creó. El admin sí puede ordenar el
        // calendario del centro.
        if (! $this->esSuyoOEsAdmin($request->user(), $calendarEvent->created_by, 'este evento')) {
            return $this->noAutorizadoPorAutoria('este evento');
        }

        $data = $request->validate([
            'title'       => ['sometimes', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],

            'start_at' => ['sometimes', new FechaRazonable()],
            'end_at'   => ['sometimes', new FechaRazonable()],

            'event_type' => ['sometimes', Rule::in(['exam', 'activity', 'reminder', 'meeting'])],

            'exam_id'  => ['nullable', 'uuid'],

            // Un evento de sección siempre tiene aula: no se puede dejar en `null`.
            'group_id' => ['sometimes', 'uuid'],

            // Público de un aviso del centro (solo admin, y solo en avisos del centro).
            'audience' => ['sometimes', Rule::in(CalendarEvent::AUDIENCES)],
        ]);

        if (array_key_exists('audience', $data) && ($request->user()->user_type !== UserType::Admin || $calendarEvent->audience === null)) {
            return response()->json(['message' => 'Solo se cambia el público de un aviso del centro, y lo hace el administrador.'], 403);
        }

        // Si vienen ambos, validamos consistencia
        $start = $data['start_at'] ?? $calendarEvent->start_at;
        $end   = $data['end_at'] ?? $calendarEvent->end_at;

        if (strtotime((string)$end) < strtotime((string)$start)) {
            return response()->json([
                'message' => 'end_at debe ser mayor o igual a start_at',
            ], 422);
        }

        if (!empty($data['exam_id']) && !$this->examenVisible($request->user(), $data['exam_id'])) {
            return response()->json(['message' => 'Examen no encontrado'], 404);
        }

        // Mover el aviso a otra aula exige que sea una de las suyas.
        if (isset($data['group_id']) && $data['group_id'] !== $calendarEvent->group_id) {
            $grupos = $this->resolverAulasDestino($request->user(), [$data['group_id']]);
            if ($grupos instanceof JsonResponse) {
                return $grupos;
            }
        }

        if (isset($data['title'])) {
            $data['title'] = trim($data['title']);
        }

        $calendarEvent->fill($data);
        $calendarEvent->save();

        return response()->json([
            'data' => $calendarEvent->fresh()->load(['creator:id,full_name', 'group:id,name,grade,section', 'exam:id,title']),
        ]);
    }

    /**
     * Eliminar evento
     */
    public function destroy(Request $request, CalendarEvent $calendarEvent)
    {
        if (! $this->esSuyoOEsAdmin($request->user(), $calendarEvent->created_by, 'este evento')) {
            return $this->noAutorizadoPorAutoria('este evento');
        }

        $calendarEvent->delete();

        return response()->noContent();
    }

    /**
     * El examen enlazado tiene que existir en la institución y ser visible para
     * quien crea el aviso (un docente solo enlaza los suyos). Antes el `uuid` se
     * guardaba sin comprobar nada.
     */
    private function examenVisible(object $user, string $examId): bool
    {
        return Exam::query()->whereKey($examId)->visibleTo($user)->exists();
    }
}
