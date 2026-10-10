<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AcotaAlDocente;
use App\Http\Controllers\Controller;
use App\Models\Academic\Subject;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;
use App\Models\Exams\Exam;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    use AcotaAlDocente;

    /**
     * Estadísticas generales de la institución.
     * GET /api/analytics/institution
     */
    public function institution(Request $request)
    {
        $user = $request->user();

        // Mismo criterio que `subjects()` (S5): el docente ve las cifras de
        // SUS alumnos; el administrador, las de toda la institución.
        $students = fn () => $this->acotarAEstudiantesDelDocente(Student::query(), $user, 'user_id');
        $attempts = fn () => $this->acotarAEstudiantesDelDocente(
            ExamAttempt::whereNotNull('submitted_at'), $user
        );

        $totalStudents  = $students()->count();
        $activeStudents = $students()->where('status', 'active')->count();
        $examsCompleted = $attempts()->count();

        $avgPct = $attempts()
            ->where('max_score', '>', 0)
            ->selectRaw('AVG(score / max_score * 100) as avg_pct')
            ->value('avg_pct');

        return response()->json([
            'data' => [
                'total_students'    => $totalStudents,
                'active_students'   => $activeStudents,
                'exams_completed'   => $examsCompleted,
                'average_score_pct' => $avgPct ? round((float) $avgPct, 2) : 0,
            ],
        ]);
    }

    /**
     * Rendimiento por materia.
     * GET /api/analytics/subjects
     */
    public function subjects(Request $request)
    {
        $query = Subject::query()->select('id', 'name');

        /*
        | Decisión S5: **el docente ve el rendimiento de las materias que
        | imparte; el administrador, el de todas.**
        |
        | Antes devolvía el centro entero a cualquiera de los dos. No era una
        | fuga de datos personales —son agregados sin nombres, y [173] le
        | concede al docente «métricas agregadas»— pero sí le ponía delante el
        | desempeño de las clases de sus colegas, que no es asunto suyo. Es la
        | misma frontera que ya aplica el resto del sistema: se alcanza lo que
        | se tiene asignado.
        |
        | Un docente sin asignaciones recibe una lista vacía, igual que en
        | `/students` o `/groups`.
        */
        if ($this->esDocente($request->user())) {
            $query->whereIn('id', $this->materiasDelDocente($request->user()->id));
        }

        $subjects = $query->get();

        return response()->json(['data' => $this->buildSubjectsPayload($subjects)]);
    }

    /**
     * Rendimiento por materia de un docente elegido por el admin.
     * GET /api/analytics/teachers/{teacherUserId}/subjects
     *
     * Mismo cálculo que `subjects()`, acotado a las materias que imparte este
     * docente en concreto en vez de a quien esté autenticado. `materiasDelDocente()`
     * ya acepta el id como parámetro explícito, por eso esto es solo plomería.
     */
    public function teacherSubjects(Request $request, string $teacherUserId)
    {
        $this->resolverDocente($teacherUserId);

        $subjects = Subject::query()
            ->select('id', 'name')
            ->whereIn('id', $this->materiasDelDocente($teacherUserId))
            ->get();

        return response()->json(['data' => $this->buildSubjectsPayload($subjects)]);
    }

    /**
     * Arma el payload de rendimiento por materia para un conjunto de materias
     * ya resuelto (todas las del centro, las de un docente, etc.).
     */
    private function buildSubjectsPayload($subjects)
    {
        $subjectIds = $subjects->pluck('id');

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

        return $subjects->map(function ($subject) use ($progressStats, $examCounts) {
            $ps = $progressStats->get($subject->id);
            $ec = $examCounts->get($subject->id);

            return [
                'id'                => $subject->id,
                'name'              => $subject->name,
                'exams_count'       => $ec ? (int) $ec->exams_count : 0,
                'enrolled_students' => $ps ? (int) $ps->student_count : 0,
                'average_mastery'   => $ps ? round((float) $ps->avg_mastery, 2) : 0,
            ];
        });
    }

    /**
     * Detalle analítico de un estudiante.
     * GET /api/analytics/students/{student_user_id}
     */
    public function student(Request $request, string $student_user_id)
    {
        $student = Student::with(['user', 'progress'])
            ->where('user_id', $student_user_id)
            ->firstOrFail();

        // Este endpoint no comprobaba nada: cualquier docente sacaba la analítica
        // individual —nota media, intentos, progreso por materia— de cualquier
        // alumno de la institución.
        if ($this->esDocente($request->user()) && !$this->docenteAlcanzaEstudiante($request->user(), $student_user_id)) {
            return $this->noAutorizadoPorAsignacion();
        }

        // Total y promedio calculados en BD para no cargar todos los intentos en memoria
        $stats = ExamAttempt::where('student_user_id', $student_user_id)
            ->whereNotNull('submitted_at')
            ->where('max_score', '>', 0)
            ->selectRaw('COUNT(*) as total_attempts, AVG(score / max_score * 100) as avg_pct')
            ->first();

        // Solo los 10 más recientes para el listado
        $recentAttempts = ExamAttempt::where('student_user_id', $student_user_id)
            ->whereNotNull('submitted_at')
            ->with('exam')
            ->orderBy('submitted_at', 'desc')
            ->limit(10)
            ->get();

        // Las materias salen del catálogo en caché del centro (`materiasDelCentro`),
        // no de dos consultas más (progreso y exámenes recientes).
        $materias = $this->materiasDelCentro($student->institution_id);
        $student->progress->each(fn ($p) => $p->setRelation('subject', $materias->get($p->subject_id)));
        $recentAttempts->each(fn ($a) => $a->exam?->setRelation('subject', $materias->get($a->exam->subject_id)));

        return response()->json([
            'data' => [
                'student'           => $student,
                'attempts_count'    => (int) ($stats->total_attempts ?? 0),
                'average_score_pct' => $stats->avg_pct ? round((float) $stats->avg_pct, 2) : 0,
                'progress'          => $student->progress,
                'recent_attempts'   => $recentAttempts,
            ],
        ]);
    }
}
