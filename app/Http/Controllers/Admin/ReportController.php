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
use App\Services\Academic\TopicMasteryService;
use App\Services\AI\ExamAnalysisNarrative;
use App\Services\Exams\ExamGroupAnalysisService;
use App\Support\RelacionesEnLinea;
use App\Support\TenantCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    use AcotaAlDocente;


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
            ->where('exam_attempts.exam_id', $exam->id)
            ->whereNotNull('exam_attempts.submitted_at')
            ->orderByDesc('exam_attempts.score');

        // El nombre del alumno unido en la misma consulta (`RelacionesEnLinea`): antes eran
        // tres (intentos, alumnos y usuarios).
        $paginator = RelacionesEnLinea::unir($paginator, ['alumno' => ['users', 'student_user_id', ['id', 'full_name']]]);
        $paginator = $this->paginar($paginator, $request, (int) config('pagination.reports'));
        RelacionesEnLinea::hidratar($paginator->getCollection(), ['alumno' => \App\Models\Admin\User::class]);

        $paginator->through(fn ($a) => [
            'attempt_id'      => $a->id,
            'student_user_id' => $a->student_user_id,
            'student_name'    => $a->alumno?->full_name,
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
     * Export XLSX: resultados de un examen. Mismo dataset y mismos permisos que
     * la versión CSV; cambia solo la serialización.
     */
    public function exportExamResultsXlsx(Exam $exam, Request $request): StreamedResponse
    {
        if (!$this->assertCanAccessExam($exam, $request)) {
            abort(403, 'No autorizado');
        }

        return $this->exports->examResultsXlsx($exam);
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
     * GET /api/reports/exams/{exam}/analysis
     *
     * Análisis de todas las respuestas del examen entre el alumnado al que se
     * asignó (ver `ExamGroupAnalysisService`): preguntas y temas más flojos,
     * errores que se repiten, aulas y a quién atender. El docente solo accede a
     * SUS exámenes; el administrador, a los del centro.
     *
     * Se cachea por la huella de los intentos (cuántos y el último cambio) y por
     * la versión del catálogo (aulas y matrículas): cada entrega o revisión
     * genera una clave nueva, así que nunca se sirve un análisis anterior a ellas.
     */
    public function examAnalysis(Exam $exam, Request $request, ExamGroupAnalysisService $analisis, ExamAnalysisNarrative $narrador)
    {
        if (!$this->assertCanAccessExam($exam, $request)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $datos = $this->analisisCacheado($exam, $analisis);

        // La lectura en palabras, calculada con reglas: no cuesta consultas ni
        // llama a OpenAI. La redactada por el modelo se pide aparte (`/analysis/ai`).
        return response()->json(['data' => $datos + ['narrative' => $narrador->heuristica($datos)]]);
    }

    /**
     * POST /api/reports/exams/{exam}/analysis/ai
     *
     * Mismo análisis, con la lectura redactada por el modelo a partir de datos
     * agregados y SIN nombres de estudiantes. Es a petición porque cuesta
     * créditos: lleva el límite de IA y el presupuesto global del centro. Si el
     * modelo no responde, devuelve la lectura calculada (`narrative.source` lo
     * dice) y no falla.
     */
    public function examAnalysisAi(Exam $exam, Request $request, ExamGroupAnalysisService $analisis, ExamAnalysisNarrative $narrador)
    {
        if (!$this->assertCanAccessExam($exam, $request)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $datos = $this->analisisCacheado($exam, $analisis);

        return response()->json(['data' => $datos + ['narrative' => $narrador->conIa($datos)]]);
    }

    /** @return array<string,mixed> */
    private function analisisCacheado(Exam $exam, ExamGroupAnalysisService $analisis): array
    {

        $huella = DB::table('exam_attempts')
            ->where('institution_id', $exam->institution_id)
            ->where('exam_id', $exam->id)
            ->selectRaw('COUNT(*) AS n, MAX(updated_at) AS ultimo')
            ->first();

        return TenantCache::remember(
            $exam->institution_id,
            TenantCache::CATALOGO,
            "exam-analysis:{$exam->id}:" . md5(json_encode([$huella->n, $huella->ultimo, (string) $exam->updated_at])),
            600,
            fn () => $analisis->analizar($exam->loadMissing('subject:id,name'))
        );
    }

    /**
     * Export CSV: historial completo de un estudiante.
     */
    public function exportStudentHistoryCsv(string $student_user_id, Request $request): StreamedResponse
    {
        return $this->exports->studentHistoryCsv($this->findStudent($student_user_id, $request->user()));
    }

    /**
     * Export XLSX: historial completo de un estudiante. El alcance por
     * asignación lo sigue decidiendo `findStudent()`, igual que el CSV.
     */
    public function exportStudentHistoryXlsx(string $student_user_id, Request $request): StreamedResponse
    {
        return $this->exports->studentHistoryXlsx($this->findStudent($student_user_id, $request->user()));
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
            ->whereNotNull('exam_attempts.submitted_at')
            ->orderByDesc('exam_attempts.submitted_at');

        // El examen unido en la misma consulta (`RelacionesEnLinea`), no una más.
        $paginator = RelacionesEnLinea::unir($paginator, ['exam' => ['exams', 'exam_id', Exam::COLUMNAS]]);

        // Sin el COUNT cuando la página no se llena (`paginar`), y las materias del
        // catálogo en caché en vez de otra consulta.
        $paginator = $this->paginar($paginator, $request, (int) config('pagination.reports'));
        RelacionesEnLinea::hidratar($paginator->getCollection(), ['exam' => Exam::class]);
        $materias = $this->materiasDelCentro($student->institution_id);
        $paginator->getCollection()->each(fn ($a) => $a->exam?->setRelation('subject', $materias->get($a->exam->subject_id)));

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
        $student = Student::conUsuario($request->user()->id);

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

    /** @return array{subject_id?:string,exam_id?:string,limit?:int} */
    private function strategyFilters(Request $request): array
    {
        return $request->validate([
            'subject_id' => ['sometimes', 'uuid'],
            'exam_id'    => ['sometimes', 'uuid'],
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
        // El alumno y su usuario en una sola consulta (`Student::conUsuario`).
        $student = $this->alumnoConUsuarioVisiblePor($viewer, $student_user_id) ?? abort(404);

        if ($this->noAlcanzaAlAlumno($viewer, $student)) {
            abort(403, 'No autorizado: no estás asignado a ningún grupo de este estudiante.');
        }

        return $student;
    }

    /**
     * Temas que conviene reforzar, agregados (decisión D2).
     *
     * Responde a lo que [173] promete al personal docente: saber **qué temas**
     * necesitan repaso, no solo qué materias van flojas. Sale de los ítems que
     * el propio profesorado etiquetó con `topic` al redactarlos.
     *
     * **Es un agregado sin nombres, a propósito.** [173] concede al docente
     * «solo métricas agregadas» y «reportes anónimos»; un listado de qué alumno
     * falló qué tema sería otra cosa. Quien necesite el detalle individual lo
     * tiene en el historial del estudiante, que sí exige alcance por asignación.
     *
     * El alcance: un docente ve los temas de los estudiantes de **sus grupos
     * asignados**; un admin, los de toda su institución (vía `TenantScoped`).
     */
    public function topicMastery(Request $request, TopicMasteryService $topics)
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        $limite = (int) ($validated['limit'] ?? 10);
        $user   = $request->user();

        $temas = $this->esDocente($user)
            ? $topics->porEstudiantes($this->estudiantesDelDocente($user->id), $limite)
            : $topics->porTodos($limite);

        return response()->json([
            'data' => [
                'threshold' => TopicMasteryService::UMBRAL_REFUERZO,
                'min_answers' => TopicMasteryService::MINIMO_RESPUESTAS,
                'topics'    => $temas,
            ],
        ]);
    }

    /**
     * Dominio por tema de los estudiantes de un docente elegido por el admin.
     * GET /api/reports/teachers/{teacherUserId}/topics
     *
     * Mismo agregado sin nombres que `topicMastery()`, acotado al docente que
     * el admin elige en vez de a quien esté autenticado. `estudiantesDelDocente()`
     * ya acepta el id como parámetro explícito, por eso esto es solo plomería.
     */
    public function teacherTopicMastery(Request $request, TopicMasteryService $topics, string $teacherUserId)
    {
        $this->resolverDocente($teacherUserId);

        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        $limite = (int) ($validated['limit'] ?? 10);

        return response()->json([
            'data' => [
                'threshold'   => TopicMasteryService::UMBRAL_REFUERZO,
                'min_answers' => TopicMasteryService::MINIMO_RESPUESTAS,
                'topics'      => $topics->porEstudiantes($this->estudiantesDelDocente($teacherUserId), $limite),
            ],
        ]);
    }

    /**
     * Métricas de uso del tutor IA:
     * - total de sesiones y mensajes
     * - sesiones activas vs cerradas
     * - top 5 tipos de recomendación más generados
     * - distribución de uso por estudiante (top 10) — **solo admin**
     * - uso por aula (alumnado que lo usó, sesiones y mensajes) — admin y docente
     *
     * **Alcance.** El administrador ve el centro entero. El docente, solo SUS estudiantes
     * (matriculados ahora en las aulas que tiene asignadas) y las recomendaciones de las materias que
     * imparte, igual que el resto de reportes por docente: antes contaba a todo el centro.
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
     * artefacto *pedagógico*, no al rastro de la conversación. En su lugar recibe
     * el uso POR AULA, que sí es un agregado.
     */
    public function tutorUsage(Request $request)
    {
        $user = $request->user();
        $esAdmin = $user->user_type->value === 'admin';
        $esDocente = $this->esDocente($user);

        $sessions = AiChatSession::query()
            ->when($esDocente, fn ($q) => $q->whereIn('student_user_id', $this->estudiantesDelDocente($user->id)))
            ->selectRaw(
                'COUNT(*) as total_sessions,
                 COUNT(CASE WHEN ended_at IS NULL THEN 1 END) as active_sessions,
                 COUNT(CASE WHEN ended_at IS NOT NULL THEN 1 END) as closed_sessions,
                 COUNT(DISTINCT student_user_id) as unique_students,
                 COALESCE(SUM(jsonb_array_length(messages)), 0) as total_messages'
            )->first();

        $topRecommendationTypes = AiRecommendation::query()
            ->when($esDocente, fn ($q) => $q
                ->whereIn('student_user_id', $this->estudiantesDelDocente($user->id))
                ->whereIn('subject_id', $this->materiasDelDocente($user->id)))
            ->select('recommendation_type', DB::raw('COUNT(*) as total'))
            ->groupBy('recommendation_type')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'type'  => $r->recommendation_type instanceof \BackedEnum ? $r->recommendation_type->value : $r->recommendation_type,
                'total' => (int) $r->total,
            ]);

        $data = [
            // De quién son las cifras: `teacher` = sus estudiantes y sus materias; `center` = todo el centro.
            // El front lo usa para rotular las tarjetas («a tus estudiantes» / «del centro»).
            'scope'                    => $esDocente ? 'teacher' : 'center',
            'sessions'                 => $sessions,
            'top_recommendation_types' => $topRecommendationTypes,
            'usage_by_group'           => $this->usoDelTutorPorAula($user, $esDocente),
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

    /**
     * Uso del tutor por aula: de cuántos estudiantes matriculados ahora, cuántos lo han usado y cuántas
     * sesiones y mensajes suman. Es un agregado (no identifica a nadie), por eso lo ve también el docente,
     * acotado a las aulas que tiene asignadas.
     *
     * @return array<int,array{group_id:string,name:string,students:int,students_using:int,sessions:int,messages:int}>
     */
    private function usoDelTutorPorAula(object $user, bool $esDocente): array
    {
        $centro = $user->institution_id;

        // Sesiones y mensajes por alumno, UNA vez; luego se suman por aula.
        $porAlumno = DB::table('ai_chat_sessions')
            ->where('institution_id', $centro)
            ->groupBy('student_user_id')
            ->selectRaw('student_user_id, COUNT(*) AS sesiones, COALESCE(SUM(jsonb_array_length(messages)), 0) AS mensajes');

        return DB::table('groups as g')
            ->join('group_students as gs', function ($join) {
                $join->on('gs.group_id', '=', 'g.id')->on('gs.institution_id', '=', 'g.institution_id')->whereNull('gs.left_at');
            })
            ->leftJoinSub($porAlumno, 'u', 'u.student_user_id', '=', 'gs.student_user_id')
            ->where('g.institution_id', $centro)
            ->when($esDocente, fn ($q) => $q->whereIn('g.id', $this->gruposDelDocente($user->id)))
            ->groupBy('g.id', 'g.name')
            ->orderBy('g.name')
            ->selectRaw('g.id, g.name, COUNT(gs.student_user_id) AS estudiantes, COUNT(u.student_user_id) AS usando,
                         COALESCE(SUM(u.sesiones), 0) AS sesiones, COALESCE(SUM(u.mensajes), 0) AS mensajes')
            ->get()
            ->map(fn ($f) => [
                'group_id'       => $f->id,
                'name'           => $f->name,
                'students'       => (int) $f->estudiantes,
                'students_using' => (int) $f->usando,
                'sessions'       => (int) $f->sesiones,
                'messages'       => (int) $f->mensajes,
            ])
            ->all();
    }
}
