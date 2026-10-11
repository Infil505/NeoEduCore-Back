<?php

namespace App\Http\Controllers\Exams;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AcotaAlDocente;
use App\Http\Controllers\Concerns\AcotaExamenAlEstudiante;
use App\Http\Controllers\Concerns\RevelaRespuestas;
use App\Enums\ExamStatus;
use App\Jobs\NotificarExamenDisponible;
use App\Models\Admin\Institution;
use App\Models\Exams\Exam;
use App\Rules\FechaRazonable;
use App\Rules\EnlaceDeApoyo;
use App\Rules\UrlDeVideo;
use App\Models\Academic\Group;
use App\Models\Admin\User;
use App\Rules\MateriaDelCentro;
use App\Support\AjustesDelCentro;
use App\Support\CatalogoGrupos;
use App\Support\PreguntasEnLinea;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;
use App\Support\RelacionesEnLinea;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExamController extends Controller
{
    use RevelaRespuestas, AcotaExamenAlEstudiante, AcotaAlDocente;

    /**
     * Minutos máximos que puede durar un examen en esta institución.
     *
     * Lo fija el administrador (el director) en `/system/config`
     * (`max_exam_duration`) y **ningún examen puede pasarlo**, tampoco el del
     * docente. Hasta el 05/10/2026 el ajuste se guardaba pero no lo leía nadie:
     * la validación tenía un 300 fijo.
     */
    private function limiteDuracion(object $user): int
    {
        // Ajustes del centro desde la caché (`AjustesDelCentro`): sin consulta a la base.
        return (int) AjustesDelCentro::de($user->institution_id)['max_exam_duration'];
    }

    /**
     * Resuelve los grupos destino comprobando que el docente los tenga
     * asignados **en la materia del examen**.
     *
     * Devuelve un array de ids válidos, o una respuesta 403 si alguno queda
     * fuera de su asignación. El fallo es explícito y nombra el grupo: antes
     * los ids no válidos se descartaban en silencio y el docente creía haber
     * publicado un examen que no llegaba a nadie.
     *
     * Para admin no hay restricción de asignación, solo la de tenant.
     *
     * @return array<int,string>|\Illuminate\Http\JsonResponse
     */
    private function resolverGruposDestino(array $groupIds, string $subjectId, object $user)
    {
        // Existencia desde el catálogo de aulas en caché (solo las del centro del usuario).
        $catalogo = CatalogoGrupos::delCentro($user->institution_id);
        $grupos = array_values(array_filter($groupIds, fn ($id) => $catalogo->has($id)));

        $fuera = array_diff($groupIds, $grupos);

        if (!$this->esDocente($user)) {
            if (!empty($fuera)) {
                return response()->json([
                    'message' => 'Algún grupo no existe en esta institución.',
                    'grupos_invalidos' => array_values($fuera),
                ], 422);
            }

            return $grupos;
        }

        // Todas las asignaciones del docente en esta materia, en UNA consulta (antes una por aula).
        $asignados = DB::table('teacher_assignments')
            ->where('teacher_user_id', $user->id)
            ->where('subject_id', $subjectId)
            ->where('institution_id', $user->institution_id)
            ->whereIn('group_id', $grupos)
            ->pluck('group_id')
            ->all();
        $noAsignados = array_values(array_diff($grupos, $asignados));

        $noAsignados = array_merge($noAsignados, array_values($fuera));

        if (!empty($noAsignados)) {
            $nombres = $catalogo->only($noAsignados)->pluck('name')->all();

            return response()->json([
                'message' => 'No estás asignado a ' . (empty($nombres)
                    ? 'alguno de los grupos indicados'
                    : implode(', ', $nombres)) . ' en esta materia.',
                'grupos_no_asignados' => $noAsignados,
            ], 403);
        }

        return $grupos;
    }

    /**
     * El examen con `subject`, `teacher` y `groups` listos para la respuesta, sin pedirlos a la base:
     * la materia y las aulas salen del catálogo en caché y el docente es quien escribe (o, si edita
     * un administrador, se lee una sola vez). Mismo JSON que `load([...])`: la materia con `id` y
     * `name`, el docente con `id` y `full_name`, y cada aula con su `pivot`.
     *
     * @param  array<int,string>  $idsGrupos
     */
    private function conRelaciones(Exam $exam, object $user, array $idsGrupos): Exam
    {
        $materia = $exam->subject_id ? $this->materiasDelCentro($exam->institution_id)->get($exam->subject_id) : null;
        $exam->setRelation('subject', $materia ? (clone $materia)->setVisible(['id', 'name']) : null);

        $docente = $exam->created_by_teacher_id === $user->id
            ? (new User())->newFromBuilder(['id' => $user->id, 'full_name' => $user->full_name])
            : User::query()->select('id', 'full_name')->find($exam->created_by_teacher_id);
        $exam->setRelation('teacher', $docente);

        $catalogo = CatalogoGrupos::delCentro($exam->institution_id);
        $exam->setRelation('groups', new EloquentCollection(
            collect($idsGrupos)->map(fn ($id) => $catalogo->get($id))->filter()->map(function ($grupo) use ($exam) {
                $grupo = clone $grupo;
                $grupo->setRelation('pivot', Pivot::fromRawAttributes($exam, [
                    'exam_id' => $exam->id, 'group_id' => $grupo->id, 'institution_id' => $exam->institution_id,
                ], 'exam_targets', true));

                return $grupo;
            })->values()->all()
        ));

        return $exam;
    }

    /**
     * Listar exámenes (filtrado por tenant vía TenantScoped)
     */
    public function index(Request $request)
    {
        // Los filtros llegan a columnas `uuid`/enum: sin validar, un valor que no
        // encaja era un 500 de PostgreSQL en vez de un 422.
        $request->validate([
            'status'     => ['nullable', Rule::in(array_map(fn ($e) => $e->value, ExamStatus::cases()))],
            'subject_id' => ['nullable', 'uuid'],
            'teacher_id' => ['nullable', 'uuid'],
            'grade'      => ['nullable', 'integer'],
        ]);

        $query = Exam::query()
            // Al estudiante solo los suyos: activos, vigentes y asignados a sus
            // grupos. Antes devolvía el catálogo entero con sus ids, que era el
            // primer paso para leer enunciados ajenos vía `show()`.
            ->visibleTo($request->user());

        // Materia y docente en la MISMA consulta (LEFT JOIN, ver `RelacionesEnLinea`): con
        // `with()` eran dos viajes más. El JSON no cambia.
        $query = RelacionesEnLinea::unir($query, [
            'subject' => ['subjects', 'subject_id', ['id', 'name']],
            'teacher' => ['users', 'created_by_teacher_id', ['id', 'full_name']],
        ])->orderByDesc('exams.created_at');

        if ($request->filled('status')) {
            $query->where('exams.status', $request->string('status')->toString());
        }

        if ($request->filled('subject_id')) {
            $query->where('exams.subject_id', $request->string('subject_id')->toString());
        }

        if ($request->filled('grade')) {
            $query->where('exams.grade', (int) $request->input('grade'));
        }

        // Solo el admin puede mirar los exámenes de un docente puntual. Un
        // docente o estudiante que mande este parámetro no amplía su propio
        // alcance -ya se lo limita `visibleTo()`-, pero se gatea explícito en
        // vez de confiar en que la intersección siempre dé una lista vacía.
        if ($request->filled('teacher_id') && $request->user()->user_type->value === 'admin') {
            $query->where('exams.created_by_teacher_id', $request->string('teacher_id')->toString());
        }

        $paginator = $this->paginar($query, $request);
        RelacionesEnLinea::hidratar($paginator->getCollection(), [
            'subject' => \App\Models\Academic\Subject::class,
            'teacher' => \App\Models\Admin\User::class,
        ]);

        $this->acotarExamenes($request->user(), $paginator->getCollection());

        return response()->json([
            'data' => $paginator,
        ]);
    }

    /**
     * Crear examen
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:150'],
            'subject_id' => ['required', 'uuid', new MateriaDelCentro($request->user()->institution_id)],
            'grade' => ['required', 'integer', 'between:' . config('academic.grade_min') . ',' . config('academic.grade_max')],
            'instructions' => ['nullable', 'string', 'max:2000'],
            // Opcional: el tutor se lo da al alumnado visual o auditivo.
            'video_url' => ['nullable', 'string', 'max:255', new UrlDeVideo()],
            // Enlaces de apoyo (vídeo o texto) que el tutor usa al recomendar.
            // Los pone el docente; el modelo nunca elige una URL.
            'support_resources' => ['nullable', 'array', 'max:' . config('ai_resources.max_support_resources')],
            'support_resources.*.type' => ['required', 'in:video,text'],
            'support_resources.*.url' => ['required', 'string', 'max:255', new EnlaceDeApoyo()],
            'support_resources.*.title' => ['nullable', 'string', 'max:120'],
            'duration_minutes' => ['required', 'integer', 'between:1,' . $this->limiteDuracion($request->user())],

            // Config avanzada RN-EXAM-034/035
            'max_attempts' => ['nullable', 'integer', 'between:1,10'],
            'show_results_immediately' => ['nullable', 'boolean'],
            'allow_review_after_submission' => ['nullable', 'boolean'],
            'randomize_questions' => ['nullable', 'boolean'],

            'available_from' => ['nullable', new FechaRazonable()],
            'available_until' => ['bail', 'nullable', new FechaRazonable(), ...FechaRazonable::posteriorA($request, 'available_from')],

            // grupos objetivo (opcional)
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['uuid'],
        ]);

        $user = $request->user();

        // Se valida ANTES de crear: si el docente apunta a un grupo que no
        // tiene asignado, la petición se rechaza entera y no queda un examen
        // huérfano en draft.
        $grupos = [];

        if (!empty($data['group_ids'])) {
            $grupos = $this->resolverGruposDestino($data['group_ids'], $data['subject_id'], $user);

            if ($grupos instanceof \Illuminate\Http\JsonResponse) {
                return $grupos;
            }
        }

        $exam = Exam::create([
            'created_by_teacher_id' => $user->id,

            'title' => trim($data['title']),
            'subject_id' => $data['subject_id'],
            'grade' => (int) $data['grade'],
            'instructions' => $data['instructions'] ?? null,
            'video_url' => $data['video_url'] ?? null,
            'support_resources' => $data['support_resources'] ?? null,
            'duration_minutes' => (int) $data['duration_minutes'],

            'status' => ExamStatus::Draft->value,

            'max_attempts' => $data['max_attempts'] ?? 3,
            'show_results_immediately' => $data['show_results_immediately'] ?? true,
            'allow_review_after_submission' => $data['allow_review_after_submission'] ?? true,
            'randomize_questions' => $data['randomize_questions'] ?? false,

            'available_from' => $data['available_from'] ?? null,
            'available_until' => $data['available_until'] ?? null,
        ]);

        if (!empty($grupos)) {
            // Examen recién creado: no hay aulas previas que comparar, basta insertar.
            $exam->groups()->attach($grupos, ['institution_id' => $exam->institution_id]);
        }

        return response()->json([
            'data' => $this->conRelaciones($exam, $user, $grupos),
        ], 201);
    }

    /**
     * Ver examen
     */
    public function show(string $exam, Request $request)
    {
        // Una consulta para el examen: ya acotado a lo que el usuario puede ver (docente: los
        // suyos; alumno: aulas y ventana horaria; administrador: el centro) y con el
        // docente unido. 404 y no 403: confirmar que el examen existe ya le diría al
        // alumno que hay una prueba preparada. Antes eran el binding de ruta, la
        // comprobación de visibilidad y una consulta por relación.
        $usuario = $request->user();
        $examen = RelacionesEnLinea::unir(Exam::query()->visibleTo($usuario), [
            'teacher' => ['users', 'created_by_teacher_id', ['id', 'full_name']],
        ])->where('exams.id', $exam)->first();

        if ($examen === null) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        RelacionesEnLinea::hidratar([$examen], ['teacher' => \App\Models\Admin\User::class]);

        $examen->load('groups');
        // Preguntas y opciones en la misma consulta (ver `PreguntasEnLinea`).
        $examen->setRelation('questions', PreguntasEnLinea::obtener($examen->questions()));
        // La materia sale del catálogo en caché del centro, no de otra consulta.
        $examen->setRelation('subject', $examen->subject_id ? $this->materiasDelCentro($examen->institution_id)->get($examen->subject_id) : null);

        // Esta ruta es de lectura compartida (admin, teacher y student): sin
        // esto, un alumno vería `is_correct` de cada opción antes de entregar.
        $this->revelarRespuestas($usuario, $examen->questions);
        $this->acotarExamen($usuario, $examen);

        return response()->json([
            'data' => $examen,
        ]);
    }

    /**
     * Actualizar examen (solo si está en draft o published)
     */
    public function update(Request $request, Exam $exam)
    {
        $user = $request->user();
        if ($user->user_type->value === 'teacher' && $exam->created_by_teacher_id !== $user->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        if (!in_array($exam->status->value, [ExamStatus::Draft->value, ExamStatus::Published->value], true)) {
            return response()->json([
                'message' => 'No se puede editar un examen activo o completado',
            ], 409);
        }

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'min:3', 'max:150'],
            'subject_id' => ['sometimes', 'uuid', new MateriaDelCentro($request->user()->institution_id)],
            'grade' => ['sometimes', 'integer', 'between:' . config('academic.grade_min') . ',' . config('academic.grade_max')],
            'instructions' => ['nullable', 'string', 'max:2000'],
            // `null` lo quita; omitirlo lo deja como está.
            'video_url' => ['nullable', 'string', 'max:255', new UrlDeVideo()],
            // Enlaces de apoyo (vídeo o texto) que el tutor usa al recomendar.
            // Los pone el docente; el modelo nunca elige una URL.
            'support_resources' => ['nullable', 'array', 'max:' . config('ai_resources.max_support_resources')],
            'support_resources.*.type' => ['required', 'in:video,text'],
            'support_resources.*.url' => ['required', 'string', 'max:255', new EnlaceDeApoyo()],
            'support_resources.*.title' => ['nullable', 'string', 'max:120'],
            'duration_minutes' => ['sometimes', 'integer', 'between:1,' . $this->limiteDuracion($request->user())],

            'max_attempts' => ['sometimes', 'integer', 'between:1,10'],
            'show_results_immediately' => ['sometimes', 'boolean'],
            'allow_review_after_submission' => ['sometimes', 'boolean'],
            'randomize_questions' => ['sometimes', 'boolean'],

            'available_from' => ['nullable', new FechaRazonable()],
            'available_until' => ['bail', 'nullable', new FechaRazonable(), ...FechaRazonable::posteriorA($request, 'available_from')],

            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['uuid'],
        ]);

        // La materia puede venir cambiada en la misma petición; la asignación se
        // comprueba contra la que va a quedar, no contra la que había.
        $materiaFinal = $data['subject_id'] ?? $exam->subject_id;
        $grupos = null;

        if (array_key_exists('group_ids', $data)) {
            $grupos = empty($data['group_ids'])
                ? []
                : $this->resolverGruposDestino($data['group_ids'], $materiaFinal, $user);

            if ($grupos instanceof \Illuminate\Http\JsonResponse) {
                return $grupos;
            }
        }

        $exam->fill($data);
        $exam->save();

        if ($grupos !== null) {
            $exam->syncGroups($grupos);
        }

        // Con aulas en la petición se conocen sin volver a pedirlas; si no, se leen una vez.
        return response()->json([
            'data' => $this->conRelaciones($exam, $user, $grupos ?? $exam->groups()->pluck('groups.id')->all()),
        ]);
    }

    /**
     * Cambiar estado (draft -> published -> active -> completed)
     */
    public function setStatus(Request $request, Exam $exam)
    {
        $user = $request->user();
        if ($user->user_type->value === 'teacher' && $exam->created_by_teacher_id !== $user->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $data = $request->validate([
            'status' => ['required', Rule::in([
                ExamStatus::Draft->value,
                ExamStatus::Published->value,
                ExamStatus::Active->value,
                ExamStatus::Completed->value,
            ])],
        ]);

        // Reglas mínimas de transición (puedes endurecer si quieres)
        $current = $exam->status->value;
        $next = $data['status'];

        $allowed = [
            ExamStatus::Draft->value => [ExamStatus::Published->value],
            ExamStatus::Published->value => [ExamStatus::Active->value, ExamStatus::Draft->value],
            ExamStatus::Active->value => [ExamStatus::Completed->value],
            ExamStatus::Completed->value => [],
        ];

        if (!in_array($next, $allowed[$current], true)) {
            return response()->json([
                'message' => "Transición inválida: {$current} -> {$next}",
            ], 409);
        }

        // El límite pudo bajar después de crear el borrador: se vuelve a exigir
        // justo cuando el examen sale hacia el alumnado.
        if (in_array($next, [ExamStatus::Published->value, ExamStatus::Active->value], true)) {
            $limite = $this->limiteDuracion($user);

            if ($exam->duration_minutes > $limite) {
                return response()->json([
                    'message' => "La duración del examen ({$exam->duration_minutes} min) supera el máximo de la institución ({$limite} min). Redúcela antes de publicarlo.",
                ], 422);
            }
        }

        // No publicar si no tiene preguntas
        if ($next === ExamStatus::Published->value && $exam->questions()->count() === 0) {
            return response()->json([
                'message' => 'No se puede publicar un examen sin preguntas',
            ], 409);
        }

        /*
         | La asignación se vuelve a comprobar al publicar o activar, no solo al
         | fijar los grupos: entre una cosa y otra el admin pudo retirarle el
         | grupo al docente, y es **aquí** donde el examen pasa a estar delante
         | del alumnado. Sin esto quedaba una vía para hacer llegar un examen a
         | un grupo que ya no le corresponde.
         */
        if ($this->esDocente($user)
            && in_array($next, [ExamStatus::Published->value, ExamStatus::Active->value], true)) {
            // Aulas destino a las que el docente ya no llega, en UNA consulta (antes una por aula).
            $perdidos = collect(DB::select(
                'SELECT et.group_id FROM exam_targets et
                  WHERE et.exam_id = ? AND et.institution_id = ?
                    AND NOT EXISTS (SELECT 1 FROM teacher_assignments ta
                                     WHERE ta.teacher_user_id = ? AND ta.group_id = et.group_id
                                       AND ta.subject_id = ? AND ta.institution_id = et.institution_id)',
                [$exam->id, $exam->institution_id, $user->id, $exam->subject_id]
            ))->pluck('group_id');

            if ($perdidos->isNotEmpty()) {
                return response()->json([
                    'message' => 'Ya no estás asignado a alguno de los grupos destino de este examen.',
                    'grupos_no_asignados' => $perdidos->all(),
                ], 403);
            }
        }

        // No activar si la ventana ya expiró
        if ($next === ExamStatus::Active->value) {
            if ($exam->available_until && now()->gt($exam->available_until)) {
                return response()->json([
                    'message' => 'No se puede activar: la ventana de disponibilidad ya expiró',
                ], 409);
            }
        }

        $exam->status = $next;
        $exam->save();

        // O1: al activarse, el alumnado de los grupos destino recibe el aviso.
        // `active` solo se alcanza una vez (no hay vuelta desde `completed`),
        // así que no puede llegar duplicado.
        if ($next === ExamStatus::Active->value) {
            NotificarExamenDisponible::dispatch($exam->id);

            // En el calendario de cada aula destino.
            $exam->publicarEnCalendario();

            // Y en vivo, para quien tiene la página abierta (WebSocket).
            event(\App\Events\ExamenActivado::de($exam));
        }

        return response()->json([
            'data' => $exam,
        ]);
    }

    /**
     * Eliminar examen (solo draft)
     */
    public function destroy(Request $request, Exam $exam)
    {
        $user = $request->user();
        if ($user->user_type->value === 'teacher' && $exam->created_by_teacher_id !== $user->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        if ($exam->status->value !== ExamStatus::Draft->value) {
            return response()->json([
                'message' => 'Solo se pueden eliminar exámenes en estado draft',
            ], 409);
        }

        $exam->delete();

        return response()->noContent();
    }
}
