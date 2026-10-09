<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Http\Controllers\Concerns\AcotaAlDocente;
use App\Models\Academic\CalendarEvent;
use App\Models\Academic\Group;
use App\Models\Academic\StudyResource;
use App\Models\Academic\Subject;
use App\Models\Academic\TeacherAssignment;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\AI\AiChatSession;
use App\Models\AI\AiRecommendation;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;
use App\Services\Admin\StaffSummaryService;
use App\Support\TenantCache;
use App\Services\Students\StudentSubjectsService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class OverviewController extends Controller
{
    use AcotaAlDocente;

    /** Secciones que puede devolver `staffOverview` (`?include=a,b,c`). */
    private const SECCIONES_STAFF = [
        'users', 'students', 'subjects', 'groups', 'exams', 'calendar', 'resources', 'analytics', 'summary',
        'teachers', 'assignments',
    ];

    /**
     * GET /api/dashboard/staff-overview
     *
     * **`?include=` limita lo que se calcula y se devuelve.** Cada sección es un
     * grupo de consultas (el resumen completo eran 19 a ~0,5–1 s cada una contra
     * la base remota), y casi ninguna pantalla las necesita todas: el panel de
     * inicio solo `summary`, «Usuarios y roles» solo `groups` (la lista de
     * usuarios la pide aparte, completa)… Sin el parámetro devuelve todas, como
     * siempre.
     *
     * Secciones: users, students, subjects, groups, exams, calendar, resources,
     * analytics, summary, teachers, assignments. (`institutions` se pide con
     * `include_institutions=1`.) `teachers` y `assignments` son del administrador
     * (lo que carga «Académico → Docencia» en una sola petición); al docente se
     * le devuelven vacías.
     */
    public function staffOverview(Request $request, StaffSummaryService $resumen)
    {
        $user = $request->user();
        $esDocente = $this->esDocente($user);
        $includeInstitutions = $request->boolean('include_institutions')
            && $user->user_type->value === 'admin';

        $incluir = $request->query('include');
        $pedidas = is_string($incluir) && trim($incluir) !== ''
            ? array_values(array_intersect(self::SECCIONES_STAFF, array_map('trim', explode(',', $incluir))))
            : self::SECCIONES_STAFF;
        $quiere = fn (string $seccion) => in_array($seccion, $pedidas, true);

        $datos = [];

        // Catálogo (materias, aulas, docentes, asignaciones): casi no cambia y lo
        // pide cada pantalla de cada usuario, así que va por la caché por centro
        // (`TenantCache`, se invalida al tocar esos datos). El docente ve solo SUS
        // materias y aulas: su id va en la clave y nunca comparte entrada con otro.
        $alcance = $esDocente ? 'd' . $user->id : 'a';
        $catalogo = fn (string $clave, \Closure $calcular) => TenantCache::remember(
            $user->institution_id, TenantCache::CATALOGO, "overview:{$clave}:{$alcance}", 300, $calcular
        );

        // Las materias también alimentan `analytics`, aunque no se pidan.
        $subjects = collect();
        if ($quiere('subjects') || $quiere('analytics')) {
            $subjects = collect($catalogo('subjects', fn () => Subject::query()
                ->when($esDocente, fn ($query) => $query->whereIn('id', $this->materiasDelDocente($user->id)))
                ->orderBy('name')
                ->get()
                ->toArray()));
        }
        if ($quiere('subjects')) {
            $datos['subjects'] = $subjects;
        }

        // Docente: el personal del centro se le deja (directorio interno entre
        // adultos), pero del alumnado solo los suyos. Sin esto el panel le daba
        // nombre y correo de menores que no son de sus aulas, la misma puerta
        // que ya se cerró en UserController::index().
        if ($quiere('users')) {
            $datos['users'] = User::query()
                ->where('institution_id', $user->institution_id)
                ->when($esDocente, function ($query) use ($user) {
                    $alcanzados = $this->estudiantesDelDocente($user->id);

                    $query->where(fn ($w) => $w
                        ->where('user_type', '!=', UserType::Student->value)
                        ->orWhereIn('id', $alcanzados));
                })
                ->orderByDesc('created_at')
                ->limit(20)
                ->get();
        }

        // Docente: solo el alumnado de los grupos que tiene asignados. Antes
        // este endpoint devolvía el padrón completo de la institución a
        // cualquier docente, el mismo bug que ya se había corregido en
        // StudentController::index() pero no aquí.
        if ($quiere('students')) {
            $datos['students'] = Student::query()
                ->with('user')
                ->when($esDocente, fn ($query) => $this->acotarAEstudiantesDelDocente($query, $user, 'user_id'))
                ->orderBy('student_code')
                ->limit(20)
                ->get();
        }

        // Docente: solo los grupos que tiene asignados (mismo criterio que
        // GroupController::index()).
        if ($quiere('groups')) {
            $datos['groups'] = $catalogo('groups', fn () => Group::query()
                ->when($esDocente, fn ($query) => $query->whereIn('id', $this->gruposDelDocente($user->id)))
                ->orderByDesc('year')
                ->orderBy('grade')
                ->orderBy('section')
                ->get()
                ->toArray());
        }

        // Docente: solo los exámenes que él creó (mismo criterio que ya
        // exige ExamController::update()/setStatus()/destroy() vía
        // created_by_teacher_id).
        if ($quiere('exams')) {
            $datos['exams'] = Exam::query()
                ->with(['subject', 'teacher'])
                ->when($esDocente, fn ($query) => $query->where('created_by_teacher_id', $user->id))
                ->orderByDesc('created_at')
                ->limit(20)
                ->get();
        }

        // Misma regla que /calendar-events y /study-resources: el docente ve
        // solo lo suyo; el administrador, todo el centro. Sin `visibleTo()` el
        // overview le daba al docente los recursos y avisos de sus colegas.
        if ($quiere('calendar')) {
            $datos['calendar'] = CalendarEvent::query()
                ->visibleTo($user)
                ->with(['creator', 'group', 'exam'])
                ->orderBy('start_at')
                ->limit(30)
                ->get();
        }

        if ($quiere('resources')) {
            $datos['resources'] = StudyResource::query()
                ->visibleTo($user)
                ->with(['creator', 'groups'])
                ->orderByDesc('created_at')
                ->limit(20)
                ->get();
        }

        // Se calcula antes de `analytics` porque, para el administrador, las cifras
        // del centro son las mismas y se reutilizan en vez de consultarlas otra vez.
        $resumenDatos = $quiere('summary') ? $resumen->para($user) : null;

        if ($quiere('analytics')) {
            $subjectIds = $subjects->pluck('id');

            if ($resumenDatos !== null && ! $esDocente) {
                // Administrador con `summary`: ya están contadas (alcance = centro).
                $entregados = (int) $resumenDatos['attempts_submitted'];
                $avgPct = $resumenDatos['average_pct'];
                $totalAlumnos = (int) $resumenDatos['students']['total'];
                $activosAlumnos = (int) $resumenDatos['students']['active'];
            } else {
                // Una sola consulta para entregados y promedio, y otra para total
                // y activos del alumnado. Con la base remota cada consulta cuesta
                // cientos de milisegundos.
                $intentos = ExamAttempt::whereNotNull('submitted_at')
                    ->selectRaw('COUNT(*) as entregados, AVG(CASE WHEN max_score > 0 THEN score / max_score * 100 END) as avg_pct')
                    ->first();
                $entregados = (int) $intentos->entregados;
                $avgPct = $intentos->avg_pct;

                $alumnado = Student::query()
                    ->selectRaw("COUNT(*) as total, COUNT(*) FILTER (WHERE status = 'active') as activos")
                    ->first();
                $totalAlumnos = (int) $alumnado->total;
                $activosAlumnos = (int) $alumnado->activos;
            }

            $progressStats = StudentProgress::whereIn('subject_id', $subjectIds)
                ->selectRaw('subject_id, COUNT(*) as student_count, AVG(mastery_percentage) as avg_mastery')
                ->groupBy('subject_id')
                ->get()
                ->keyBy('subject_id');

            $examCounts = Exam::whereIn('subject_id', $subjectIds)
                ->selectRaw('subject_id, COUNT(*) as exams_count')
                ->groupBy('subject_id')
                ->get()
                ->keyBy('subject_id');

            $datos['analyticsInstitution'] = [
                'total_students' => $totalAlumnos,
                'active_students' => $activosAlumnos,
                'exams_completed' => $entregados,
                'average_score_pct' => $avgPct ? round((float) $avgPct, 2) : 0,
            ];

            $datos['analyticsSubjects'] = $subjects->map(function ($subject) use ($progressStats, $examCounts) {
                $progress = $progressStats->get($subject['id']);
                $examCount = $examCounts->get($subject['id']);

                return [
                    'id' => $subject['id'],
                    'name' => $subject['name'],
                    'exams_count' => $examCount ? (int) $examCount->exams_count : 0,
                    'enrolled_students' => $progress ? (int) $progress->student_count : 0,
                    'average_mastery' => $progress ? round((float) $progress->avg_mastery, 2) : 0,
                ];
            })->values();
        }

        if ($resumenDatos !== null) {
            $datos['summary'] = $resumenDatos;
        }

        // Docencia (solo administrador): los docentes y sus asignaciones docente ↔
        // aula ↔ materia, completos. Es lo que antes pedían dos listados aparte
        // (`/users?user_type=teacher` y `/teacher-assignments`), cada uno con su
        // propia autenticación. Mismos campos y orden que esos listados.
        if ($quiere('teachers')) {
            $datos['teachers'] = $esDocente ? [] : $catalogo('teachers', fn () => User::query()
                ->where('institution_id', $user->institution_id)
                ->where('user_type', UserType::Teacher->value)
                ->orderBy('full_name')
                ->limit(500)
                ->get()
                ->toArray());
        }

        if ($quiere('assignments')) {
            $datos['assignments'] = $esDocente ? [] : $catalogo('assignments', fn () => TeacherAssignment::query()
                ->with(['teacher:id,full_name,email', 'group:id,name,grade,section', 'subject:id,name'])
                ->orderByDesc('assigned_at')
                ->limit(2000)
                ->get()
                ->toArray());
        }

        $datos['institutions'] = $includeInstitutions
            ? Institution::query()->orderByDesc('created_at')->limit(20)->get()
            : [];

        return response()->json(['data' => $datos]);
    }

    public function studentOverview(Request $request)
    {
        $user = $request->user();

        $student = Student::query()
            ->with(['user', 'groups', 'progress'])
            ->where('user_id', $user->id)
            ->first();

        if (!$student) {
            return response()->json([
                'message' => 'Este usuario no tiene perfil de estudiante',
            ], 404);
        }

        // El centro es el mismo para todo el alumnado y casi no cambia: sale de la
        // caché del centro (fila cruda; se invalida al guardar la institución)
        // en vez de una consulta por alumno en cada carga del panel.
        $filaCentro = TenantCache::remember(
            $student->institution_id, TenantCache::CONFIG, 'institution-row', 600,
            fn () => Institution::query()->whereKey($student->institution_id)->toBase()->first()
        );
        if ($filaCentro !== null && $student->user !== null) {
            $student->user->setRelation('institution', (new Institution())->newFromBuilder((array) $filaCentro));
        }

        // Las materias del centro, del catálogo cacheado: antes se pedían por
        // separado para el progreso, los exámenes y las recomendaciones (3 consultas).
        $materias = $this->materiasDelCentro($student->institution_id);
        $student->progress->each(fn ($p) => $p->setRelation('subject', $materias->get($p->subject_id)));

        $groupIds = $student->groups->pluck('id');

        $exams = $groupIds->isEmpty()
            ? collect()
            : Exam::query()
                ->where('status', 'active')
                ->where(fn ($query) => $query->whereNull('available_from')->orWhere('available_from', '<=', now()))
                ->where(fn ($query) => $query->whereNull('available_until')->orWhere('available_until', '>=', now()))
                ->whereHas('groups', fn ($query) => $query->whereIn('groups.id', $groupIds))
                ->withCount(['attempts as submitted_count' => fn ($query) => $query
                    ->where('student_user_id', $user->id)
                    ->whereNotNull('submitted_at')])
                ->get()
                ->each(fn ($exam) => $exam->setRelation('subject', $materias->get($exam->subject_id)))
                ->filter(fn ($exam) => $exam->submitted_count < $exam->max_attempts)
                ->values();

        // Ya está cargado (con su materia) en `profile.progress`: se reutiliza en
        // vez de pedirlo otra vez a la base —eran dos consultas más, con ~0,5 s
        // cada una contra la base remota—. Mismo orden y mismo tope que antes.
        $progress = $student->progress
            ->sortByDesc('updated_at')
            ->take(100)
            ->values();

        $recommendations = AiRecommendation::query()
            ->with('exam')
            ->where('student_user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(15)
            ->get()
            ->each(fn ($r) => $r->setRelation('subject', $materias->get($r->subject_id)));

        // Solo los avisos de su aula actual, y del autor solo el nombre (igual
        // que /calendar-events). Antes recibía los de todo el centro.
        //
        // Lo que ve un alumno depende solo de sus aulas abiertas (y de los avisos
        // generales), así que las 4 consultas se comparten entre todo el alumnado
        // del mismo conjunto de aulas: 30 alumnos de una sección son 4 consultas,
        // no 120. Se invalida al tocar avisos, exámenes o aulas (`AGENDA`).
        $aulasAbiertas = $student->groups
            ->filter(fn ($grupo) => $grupo->pivot->left_at === null)
            ->pluck('id')->sort()->values();

        $calendar = collect(TenantCache::remember(
            $student->institution_id, TenantCache::AGENDA, 'student-calendar:' . md5($aulasAbiertas->implode(',')), 120,
            fn () => CalendarEvent::query()
                ->visibleTo($user)
                ->with(['creator:id,full_name', 'group', 'exam'])
                ->orderBy('start_at')
                ->limit(30)
                ->get()
                ->toArray()
        ));

        $sessions = AiChatSession::query()
            ->select('id', 'student_user_id', 'subject_id', 'exam_id', 'ended_at', 'created_at', 'updated_at')
            ->where('student_user_id', $user->id)
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();

        // Las de su sección más las individuales (ver StudentSubjectsService).
        $subjects = app(StudentSubjectsService::class)->materias($student->user_id, $student->institution_id);

        return response()->json([
            'data' => [
                'profile' => $student,
                'exams' => $exams,
                'progress' => $progress,
                'recommendations' => $recommendations,
                'calendar' => $calendar,
                'tutorSessions' => $sessions,
                'diagnosis' => $this->buildStudentDiagnosis($student),
                'subjects' => $subjects,
            ],
        ]);
    }

    /**
     * Materias del centro por id, del catálogo cacheado (`TenantCache::CATALOGO`,
     * se invalida al tocar una materia). Filas crudas en la caché y modelos
     * armados en cada petición, para no guardar objetos Eloquent.
     *
     * @return Collection<string,Subject>
     */
    private function materiasDelCentro(string $centro): Collection
    {
        $filas = TenantCache::remember(
            $centro, TenantCache::CATALOGO, 'subjects-map', 300,
            fn () => Subject::query()->toBase()->get()->map(fn ($fila) => (array) $fila)->all()
        );

        return collect($filas)->mapWithKeys(fn (array $fila) => [$fila['id'] => (new Subject())->newFromBuilder($fila)]);
    }

    private function buildStudentDiagnosis(Student $student): array
    {
        /** @var Collection<int, StudentProgress> $progress */
        $progress = $student->progress
            ->sortBy('mastery_percentage')
            ->values();

        if ($progress->isEmpty()) {
            return [
                'summary' => 'Aun no hay suficiente progreso registrado para generar un diagnostico detallado. Completa tu siguiente examen para empezar a ver recomendaciones.',
                'focus_area' => 'Primer avance academico',
            ];
        }

        /** @var StudentProgress $lowest */
        $lowest = $progress->first();
        /** @var StudentProgress $highest */
        $highest = $progress->sortByDesc('mastery_percentage')->first();
        $average = round((float) $progress->avg('mastery_percentage'));

        $focusArea = $lowest->subject?->name ?? 'Seguimiento general';
        $strengthArea = $highest->subject?->name ?? 'tu mejor materia actual';

        return [
            'summary' => "Tu promedio actual es {$average}%. Tu mejor avance va en {$strengthArea}, mientras que {$focusArea} necesita mas refuerzo esta semana. Prioriza practicar esa materia y luego vuelve a medir tu progreso.",
            'focus_area' => $focusArea,
        ];
    }
}
