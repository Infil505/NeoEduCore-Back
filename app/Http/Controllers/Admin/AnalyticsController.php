<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AcotaAlDocente;
use App\Http\Controllers\Controller;
use App\Models\Academic\Subject;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;
use App\Models\Exams\Exam;
use App\Support\RelacionesEnLinea;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

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

        // Una consulta por tabla con todos los conteos a la vez (antes cuatro): con la
        // base remota cada viaje cuesta ~0,4 s.
        $alumnado = $students()
            ->selectRaw("COUNT(*) as total, COUNT(*) FILTER (WHERE status = 'active') as activos")
            ->first();
        $intentos = $attempts()
            ->selectRaw('COUNT(*) as entregados, AVG(CASE WHEN max_score > 0 THEN score / max_score * 100 END) as avg_pct')
            ->first();

        $totalStudents  = (int) $alumnado->total;
        $activeStudents = (int) $alumnado->activos;
        $examsCompleted = (int) $intentos->entregados;
        $avgPct         = $intentos->avg_pct;

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

        return response()->json(['data' => $this->buildSubjectsPayload($query)]);
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
            ->whereIn('id', $this->materiasDelDocente($teacherUserId));

        return response()->json(['data' => $this->buildSubjectsPayload($subjects)]);
    }

    /**
     * Arma el payload de rendimiento por materia para un conjunto de materias
     * ya resuelto (todas las del centro, las de un docente, etc.).
     */
    private function buildSubjectsPayload($subjects)
    {
        // UNA consulta: las materias con sus cifras como subselects (antes eran tres:
        // materias, progreso y exámenes). Con la base remota cada viaje cuesta ~0,4 s.
        $filas = $subjects->conCifras()->get();

        return $filas->map(fn ($subject) => [
            'id'                => $subject->id,
            'name'              => $subject->name,
            'exams_count'       => (int) $subject->exams_count,
            'enrolled_students' => (int) $subject->student_count,
            'average_mastery'   => $subject->student_count > 0 ? round((float) $subject->avg_mastery, 2) : 0,
        ]);
    }

    /**
     * Detalle analítico de un estudiante.
     * GET /api/analytics/students/{student_user_id}
     */
    public function student(Request $request, string $student_user_id)
    {
        // El alumno con su usuario en una sola consulta (`Student::conUsuario`).
        $student = $this->alumnoConUsuarioVisiblePor($request->user(), $student_user_id) ?? abort(404);

        // Este endpoint no comprobaba nada: cualquier docente sacaba la analítica
        // individual —nota media, intentos, progreso por materia— de cualquier
        // alumno de la institución.
        if ($this->noAlcanzaAlAlumno($request->user(), $student)) {
            return $this->noAutorizadoPorAsignacion();
        }

        $student->load('progress');

        // Los 10 intentos más recientes con su examen unido, y en las mismas filas el total
        // y el promedio de TODOS los entregados (funciones de ventana: se calculan antes del
        // LIMIT). Antes eran tres consultas: estadísticas, intentos y examen.
        $recentAttempts = RelacionesEnLinea::unir(
            ExamAttempt::where('student_user_id', $student_user_id)->whereNotNull('submitted_at'),
            ['exam' => ['exams', 'exam_id', Exam::COLUMNAS]]
        )
            ->addSelect(DB::raw('COUNT(*) FILTER (WHERE exam_attempts.max_score > 0) OVER () AS total_attempts'))
            ->addSelect(DB::raw('AVG(CASE WHEN exam_attempts.max_score > 0 THEN exam_attempts.score / exam_attempts.max_score * 100 END) OVER () AS avg_pct'))
            ->orderBy('exam_attempts.submitted_at', 'desc')
            ->limit(10)
            ->get();

        $stats = $recentAttempts->first();
        $totalAttempts = (int) ($stats?->getAttribute('total_attempts') ?? 0);
        $avgPct = $stats?->getAttribute('avg_pct');
        foreach ($recentAttempts as $intento) {
            $intento->setRawAttributes(Arr::except($intento->getAttributes(), ['total_attempts', 'avg_pct']), true);
        }
        RelacionesEnLinea::hidratar($recentAttempts, ['exam' => Exam::class]);

        // Las materias salen del catálogo en caché del centro (`materiasDelCentro`),
        // no de dos consultas más (progreso y exámenes recientes).
        $materias = $this->materiasDelCentro($student->institution_id);
        $student->progress->each(fn ($p) => $p->setRelation('subject', $materias->get($p->subject_id)));
        $recentAttempts->each(fn ($a) => $a->exam?->setRelation('subject', $materias->get($a->exam->subject_id)));

        return response()->json([
            'data' => [
                'student'           => $student,
                'attempts_count'    => $totalAttempts,
                'average_score_pct' => $avgPct ? round((float) $avgPct, 2) : 0,
                'progress'          => $student->progress,
                'recent_attempts'   => $recentAttempts,
            ],
        ]);
    }
}
