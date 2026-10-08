<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Http\Controllers\Concerns\AcotaAlDocente;
use App\Models\Academic\CalendarEvent;
use App\Models\Academic\Group;
use App\Models\Academic\StudyResource;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\AI\AiChatSession;
use App\Models\AI\AiRecommendation;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;
use App\Services\Students\StudentSubjectsService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class OverviewController extends Controller
{
    use AcotaAlDocente;

    public function staffOverview(Request $request)
    {
        $user = $request->user();
        $institutionId = $user->institution_id;
        $esDocente = $this->esDocente($user);
        $includeInstitutions = $request->boolean('include_institutions')
            && $user->user_type->value === 'admin';

        $subjects = Subject::query()
            ->when($esDocente, fn ($query) => $query->whereIn('id', $this->materiasDelDocente($user->id)))
            ->orderBy('name')
            ->get();

        $subjectIds = $subjects->pluck('id');

        // Docente: el personal del centro se le deja (directorio interno entre
        // adultos), pero del alumnado solo los suyos. Sin esto el panel le daba
        // nombre y correo de menores que no son de sus aulas, la misma puerta
        // que ya se cerró en UserController::index().
        $users = User::query()
            ->where('institution_id', $institutionId)
            ->when($esDocente, function ($query) use ($user) {
                $alcanzados = $this->estudiantesDelDocente($user->id);

                $query->where(fn ($w) => $w
                    ->where('user_type', '!=', UserType::Student->value)
                    ->orWhereIn('id', $alcanzados));
            })
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        // Docente: solo el alumnado de los grupos que tiene asignados. Antes
        // este endpoint devolvía el padrón completo de la institución a
        // cualquier docente, el mismo bug que ya se había corregido en
        // StudentController::index() pero no aquí.
        $students = Student::query()
            ->with('user')
            ->when($esDocente, fn ($query) => $this->acotarAEstudiantesDelDocente($query, $user, 'user_id'))
            ->orderBy('student_code')
            ->limit(20)
            ->get();

        // Docente: solo los grupos que tiene asignados (mismo criterio que
        // GroupController::index()).
        $groups = Group::query()
            ->when($esDocente, fn ($query) => $query->whereIn('id', $this->gruposDelDocente($user->id)))
            ->orderByDesc('year')
            ->orderBy('grade')
            ->orderBy('section')
            ->get();

        // Docente: solo los exámenes que él creó (mismo criterio que ya
        // exige ExamController::update()/setStatus()/destroy() vía
        // created_by_teacher_id).
        $exams = Exam::query()
            ->with(['subject', 'teacher'])
            ->when($esDocente, fn ($query) => $query->where('created_by_teacher_id', $user->id))
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        // Cuántos lo están presentando, lo entregaron o les falta (columna de la
        // tabla de exámenes). Los borradores no tienen a nadie presentándolos.
        $resumen = app(\App\Services\Exams\ExamMonitorService::class)
            ->resumen($exams->reject(fn ($exam) => $exam->status->value === 'draft'));
        $exams->each(fn ($exam) => $exam->setAttribute('monitor', $resumen[$exam->id] ?? null));

        // Misma regla que /calendar-events y /study-resources: el docente ve
        // solo lo suyo; el administrador, todo el centro. Sin `visibleTo()` el
        // overview le daba al docente los recursos y avisos de sus colegas.
        // Los más recientes primero (antes eran los 30 más ANTIGUOS: con el
        // tiempo, los avisos nuevos dejaban de aparecer en las listas).
        $calendar = CalendarEvent::query()
            ->visibleTo($user)
            ->with(['creator', 'group', 'exam'])
            ->orderByDesc('start_at')
            ->limit(100)
            ->get();

        $resources = StudyResource::query()
            ->visibleTo($user)
            ->with(['creator', 'groups'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        // Cifras reales del panel, contadas en la BD con el alcance del rol
        // (las listas de arriba están recortadas y no sirven para contar).
        $summary = app(\App\Services\Dashboard\StaffSummaryService::class)->resumen($user, $esDocente);

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

        $analyticsSubjects = $subjects->map(function ($subject) use ($progressStats, $examCounts) {
            $progress = $progressStats->get($subject->id);
            $examCount = $examCounts->get($subject->id);

            return [
                'id' => $subject->id,
                'name' => $subject->name,
                'exams_count' => $examCount ? (int) $examCount->exams_count : 0,
                'enrolled_students' => $progress ? (int) $progress->student_count : 0,
                'average_mastery' => $progress ? round((float) $progress->avg_mastery, 2) : 0,
            ];
        })->values();

        return response()->json([
            'data' => [
                'users' => $users,
                'students' => $students,
                'subjects' => $subjects,
                'groups' => $groups,
                'exams' => $exams,
                'calendar' => $calendar,
                'resources' => $resources,
                'summary' => $summary,
                // Con el alcance del rol: al docente, sus estudiantes y sus exámenes
                // (antes recibía las cifras de todo el centro).
                'analyticsInstitution' => [
                    'total_students' => $summary['students']['total'],
                    'active_students' => $summary['students']['active'],
                    'exams_completed' => $summary['attempts_submitted'],
                    'average_score_pct' => $summary['average_pct'] ?? 0,
                ],
                'analyticsSubjects' => $analyticsSubjects,
                'institutions' => $includeInstitutions
                    ? Institution::query()->orderByDesc('created_at')->limit(20)->get()
                    : [],
            ],
        ]);
    }

    public function studentOverview(Request $request)
    {
        $user = $request->user();

        $student = Student::query()
            ->with(['user.institution', 'groups', 'progress.subject'])
            ->where('user_id', $user->id)
            ->first();

        if (!$student) {
            return response()->json([
                'message' => 'Este usuario no tiene perfil de estudiante',
            ], 404);
        }

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
                ->with('subject')
                ->get()
                ->filter(fn ($exam) => $exam->submitted_count < $exam->max_attempts)
                ->values();

        $progress = StudentProgress::query()
            ->with('subject')
            ->where('student_user_id', $user->id)
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();

        $recommendations = AiRecommendation::query()
            ->with(['subject', 'exam'])
            ->where('student_user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(15)
            ->get();

        // Solo los avisos de su aula actual, y del autor solo el nombre (igual
        // que /calendar-events). Antes recibía los de todo el centro.
        // Lo que viene (o está en curso), del más cercano al más lejano.
        $calendar = CalendarEvent::query()
            ->visibleTo($user)
            ->where('end_at', '>=', now())
            ->with(['creator:id,full_name', 'group', 'exam'])
            ->orderBy('start_at')
            ->limit(30)
            ->get();

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
