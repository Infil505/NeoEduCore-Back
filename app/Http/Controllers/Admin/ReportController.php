<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AcotaAlDocente;
use App\Http\Controllers\Controller;
use App\Models\AI\AiChatSession;
use App\Models\AI\AiRecommendation;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use App\Services\Admin\ReportExportService;
use App\Services\Admin\ReportMetricsService;
use App\Services\Admin\ReportStrategyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    use AcotaAlDocente;

    private const RESULTS_PER_PAGE = 50;

    public function __construct(
        private ReportExportService $exports,
        private ReportMetricsService $metrics,
        private ReportStrategyService $strategies,
    ) {
    }

    /**
     * Reporte paginado: resultados de un examen (JSON)
     */
    private function assertCanAccessExam(Exam $exam, Request $request): bool
    {
        $user = $request->user();
        if ($user->user_type->value === 'teacher' && $exam->created_by_teacher_id !== $user->id) {
            return false;
        }
        return true;
    }

    public function examResults(Exam $exam, Request $request)
    {
        if (!$this->assertCanAccessExam($exam, $request)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $paginator = ExamAttempt::query()
            ->where('exam_id', $exam->id)
            ->whereNotNull('submitted_at')
            ->with(['student.user'])
            ->orderByDesc('score')
            ->paginate(self::RESULTS_PER_PAGE);

        $paginator->through(fn ($a) => [
            'attempt_id'      => $a->id,
            'student_user_id' => $a->student_user_id,
            'student_name'    => $a->student?->user?->full_name,
            'score'           => (float) $a->score,
            'max_score'       => (float) $a->max_score,
            'percentage'      => $a->percentage,
            'submitted_at'    => $a->submitted_at,
        ]);

        return response()->json([
            'data' => [
                'exam'    => $exam,
                'results' => $paginator,
            ],
        ]);
    }

    /**
     * Export CSV: resultados de un examen (dataset completo, fila a fila).
     */
    public function exportExamResultsCsv(Exam $exam, Request $request): StreamedResponse
    {
        if (!$this->assertCanAccessExam($exam, $request)) {
            abort(403, 'No autorizado');
        }

        return $this->exports->examResultsCsv($exam);
    }

    /**
     * Resumen grupal de un examen, listo para graficar y para el PDF que arma el
     * frontend: totales, histograma por rango de nota (barras) y reparto por
     * nivel de desempeño (pastel).
     */
    public function examSummary(Exam $exam, Request $request)
    {
        if (!$this->assertCanAccessExam($exam, $request)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        return response()->json(['data' => $this->metrics->examSummary($exam)]);
    }

    /**
     * Export CSV: historial completo de un estudiante.
     */
    public function exportStudentHistoryCsv(string $student_user_id, Request $request): StreamedResponse
    {
        return $this->exports->studentHistoryCsv($this->findStudent($student_user_id, $request->user()));
    }

    /**
     * Resumen individual de un estudiante: totales, evolución de la nota en el
     * tiempo (líneas) y dominio por materia (barras).
     *
     * `?points=` ajusta cuántos intentos trae la serie de evolución.
     */
    public function studentSummary(string $student_user_id, Request $request)
    {
        $validated = $request->validate([
            'points' => ['sometimes', 'integer', 'between:1,' . ReportMetricsService::MAX_TREND_POINTS],
        ]);

        return response()->json([
            'data' => $this->metrics->studentSummary(
                $this->findStudent($student_user_id, $request->user()),
                (int) ($validated['points'] ?? ReportMetricsService::DEFAULT_TREND_POINTS),
            ),
        ]);
    }

    /**
     * Reporte: historial completo de un estudiante (JSON)
     */
    public function studentHistory(string $student_user_id, Request $request)
    {
        $student = $this->findStudent($student_user_id, $request->user());

        $paginator = ExamAttempt::query()
            ->where('student_user_id', $student_user_id)
            ->whereNotNull('submitted_at')
            ->with('exam.subject')
            ->orderByDesc('submitted_at')
            ->paginate(self::RESULTS_PER_PAGE);

        $paginator->through(fn ($a) => [
            'attempt_id'   => $a->id,
            'exam_id'      => $a->exam_id,
            'exam_title'   => $a->exam?->title,
            'subject'      => $a->exam?->subject?->name,
            'score'        => (float) $a->score,
            'max_score'    => (float) $a->max_score,
            'percentage'   => $a->percentage,
            'submitted_at' => $a->submitted_at,
        ]);

        return response()->json([
            'data' => [
                'student'  => $student,
                'attempts' => $paginator,
            ],
        ]);
    }

    /**
     * Estrategias del tutor del estudiante autenticado (requisito [740]).
     *
     * Solo recomendaciones estructuradas: el historial de chat con el tutor no
     * sale por ningún reporte, ni siquiera en el del propio alumno.
     */
    public function myStrategies(Request $request)
    {
        $student = Student::with('user')->where('user_id', $request->user()->id)->first();

        if ($student === null) {
            return response()->json(['message' => 'Este usuario no tiene perfil de estudiante'], 404);
        }

        return response()->json([
            'data' => $this->strategies->studentStrategies(
                $student,
                $request->user(),
                $this->strategyFilters($request),
            ),
        ]);
    }

    /**
     * Estrategias del tutor de un estudiante, para el docente.
     *
     * Doble filtro, y son distintos: `findStudent()` decide si el docente puede
     * mirar a este alumno (asignación al grupo), y `ReportStrategyService`
     * decide qué recomendaciones de las suyas le corresponden (materias que
     * imparte).
     */
    public function studentStrategies(string $student_user_id, Request $request)
    {
        return response()->json([
            'data' => $this->strategies->studentStrategies(
                $this->findStudent($student_user_id, $request->user()),
                $request->user(),
                $this->strategyFilters($request),
            ),
        ]);
    }

    /** @return array{subject_id?:string,limit?:int} */
    private function strategyFilters(Request $request): array
    {
        return $request->validate([
            'subject_id' => ['sometimes', 'uuid'],
            'limit'      => ['sometimes', 'integer', 'between:1,' . ReportStrategyService::MAX_LIMIT],
        ]);
    }

    /**
     * El scope de institución (`TenantScoped`) ya impide leer el historial de un
     * estudiante de otro centro: fuera de la institución del usuario, el
     * `firstOrFail()` devuelve 404.
     *
     * Dentro del centro no impedía nada: hasta ahora **cualquier docente podía
     * leer el historial completo, el resumen y las estrategias de cualquier
     * alumno de la institución**, porque ninguno de los cuatro endpoints que
     * pasan por aquí comprobaba nada más. El alcance por asignación se aplica en
     * este punto único para que no vuelva a quedarse fuera de uno de ellos.
     *
     * `$viewer` es obligatorio a propósito: si fuera opcional, un endpoint nuevo
     * podría olvidarlo y volver al agujero anterior sin que nada fallara.
     */
    private function findStudent(string $student_user_id, object $viewer): Student
    {
        $student = Student::with('user')->where('user_id', $student_user_id)->firstOrFail();

        if ($this->esDocente($viewer) && !$this->docenteAlcanzaEstudiante($viewer, $student_user_id)) {
            abort(403, 'No autorizado: no estás asignado a ningún grupo de este estudiante.');
        }

        return $student;
    }

    /**
     * Métricas de uso del tutor IA:
     * - total de sesiones y mensajes
     * - sesiones activas vs cerradas
     * - top 5 tipos de recomendación más generados
     * - distribución de uso por estudiante (top 10) — **solo admin**
     *
     * **Por qué el docente no ve el ranking nominal.** [173] es explícito: el
     * personal docente «recibirá solo métricas agregadas», y entre los
     * entregables del tutor figuran «reportes anónimos». Un top 10 con el nombre
     * de cada alumno y cuántos mensajes escribió al tutor no es un agregado
     * anónimo: identifica a menores por su comportamiento de uso, que es
     * justamente el dato que [394] se compromete a proteger.
     *
     * Se conserva para el admin, que es quien responde por los datos de la
     * institución y lo necesita para detectar abuso o coste desbocado. Es la
     * misma frontera que ya aplica `ReportStrategyService`: el docente accede al
     * artefacto *pedagógico*, no al rastro de la conversación.
     */
    public function tutorUsage(Request $request)
    {
        $esAdmin = $request->user()->user_type->value === 'admin';

        $sessions = AiChatSession::selectRaw(
            'COUNT(*) as total_sessions,
             COUNT(CASE WHEN ended_at IS NULL THEN 1 END) as active_sessions,
             COUNT(CASE WHEN ended_at IS NOT NULL THEN 1 END) as closed_sessions,
             COUNT(DISTINCT student_user_id) as unique_students,
             SUM(jsonb_array_length(messages)) as total_messages'
        )->first();

        $topRecommendationTypes = AiRecommendation::select('recommendation_type', DB::raw('COUNT(*) as total'))
            ->groupBy('recommendation_type')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'type'  => $r->recommendation_type instanceof \BackedEnum ? $r->recommendation_type->value : $r->recommendation_type,
                'total' => (int) $r->total,
            ]);

        $data = [
            'sessions'                 => $sessions,
            'top_recommendation_types' => $topRecommendationTypes,
        ];

        // La consulta ni siquiera se lanza para un docente: el dato no se
        // calcula para luego filtrarlo, sencillamente no se pide.
        if ($esAdmin) {
            $data['top_students_by_usage'] = AiChatSession::select(
                    'student_user_id',
                    DB::raw('COUNT(*) as sessions'),
                    DB::raw('SUM(jsonb_array_length(messages)) as messages')
                )
                ->groupBy('student_user_id')
                ->orderByDesc('messages')
                ->limit(10)
                ->with('student.user:id,full_name')
                ->get()
                ->map(fn ($s) => [
                    'student_user_id' => $s->student_user_id,
                    'full_name'       => $s->student?->user?->full_name ?? 'N/D',
                    'sessions'        => (int) $s->sessions,
                    'messages'        => (int) $s->messages,
                ]);
        }

        return response()->json(['data' => $data]);
    }
}
