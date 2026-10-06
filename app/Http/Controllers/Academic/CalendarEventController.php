<?php

namespace App\Http\Controllers\Academic;

use App\Enums\UserType;
use App\Http\Controllers\Concerns\ExigeAutoria;
use App\Http\Controllers\Concerns\ResuelveAulasDestino;
use App\Http\Controllers\Controller;
use App\Models\Academic\CalendarEvent;
use App\Models\Exams\Exam;
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
            'from'       => ['nullable', 'date'],
            'to'         => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $query = CalendarEvent::query()
            ->visibleTo($request->user())
            ->with(['creator', 'group', 'exam'])
            ->orderBy('start_at');

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

        $paginator = $query->paginate(config('pagination.default'));

        $this->acotarParaEstudiante($request->user(), $paginator->getCollection());

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
     * lo que decide quién lo ve. La respuesta es el evento si fue a una aula, y
     * una lista de eventos si fue a varias.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'title'       => ['required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],

            'start_at' => ['required', 'date'],
            'end_at'   => ['required', 'date', 'after_or_equal:start_at'],

            'event_type' => ['required', Rule::in(['exam', 'activity', 'reminder', 'meeting'])],

            'exam_id'  => ['nullable', 'uuid'],

            'group_id'    => ['nullable', 'uuid'],
            'group_ids'   => ['nullable', 'array'],
            'group_ids.*' => ['uuid', 'distinct'],
        ]);

        $user = $request->user();

        if (!empty($data['exam_id']) && !$this->examenVisible($user, $data['exam_id'])) {
            return response()->json(['message' => 'Examen no encontrado'], 404);
        }

        $pedidas = $data['group_ids'] ?? (isset($data['group_id']) ? [$data['group_id']] : null);

        $grupos = $this->resolverAulasDestino($user, $pedidas);
        if ($grupos instanceof JsonResponse) {
            return $grupos;
        }

        $eventos = DB::transaction(fn () => array_map(
            fn (string $grupoId) => CalendarEvent::create([
                'title' => trim($data['title']),
                'description' => $data['description'] ?? null,
                'start_at' => $data['start_at'],
                'end_at' => $data['end_at'],
                'event_type' => $data['event_type'],
                'exam_id' => $data['exam_id'] ?? null,
                'group_id' => $grupoId,
                'created_by' => $user->id,
            ])->load(['creator', 'group', 'exam']),
            $grupos
        ));

        return response()->json([
            'data' => count($eventos) === 1 ? $eventos[0] : $eventos,
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

        $calendarEvent->load(['creator', 'group', 'exam']);
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

            'start_at' => ['sometimes', 'date'],
            'end_at'   => ['sometimes', 'date'],

            'event_type' => ['sometimes', Rule::in(['exam', 'activity', 'reminder', 'meeting'])],

            'exam_id'  => ['nullable', 'uuid'],

            // Un evento siempre tiene aula: no se puede dejar en `null`.
            'group_id' => ['sometimes', 'uuid'],
        ]);

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
            'data' => $calendarEvent->fresh()->load(['creator', 'group', 'exam']),
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
