<?php

namespace App\Http\Controllers\Exams;

use App\Enums\AiGenerationSource;
use App\Enums\AiRecommendationsStatus;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateAiRecommendations;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use App\Models\Exams\Question;
use App\Services\AI\AiRecommendationService;
use App\Services\AI\FormatoPorEstilo;
use App\Services\Exams\ExamAttemptRulesService;
use App\Services\Exams\ExamGradingService;
use App\Services\Students\StudentProgressService;
use App\Models\AI\AiRecommendation;
use App\Support\RelacionesEnLinea;
use App\Support\RespuestasEnLinea;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExamAttemptController extends Controller
{
    /**
     * Iniciar intento
     */
    public function start(
        Request $request,
        Exam $exam,
        ExamAttemptRulesService $rules
    ) {
        $user = $request->user();

        // Debe ser estudiante
        $student = Student::where('user_id', $user->id)->first();
        if (!$student) {
            return response()->json(['message' => 'Solo estudiantes pueden iniciar intentos'], 403);
        }

        // Solo el examen enviado a un aula donde está matriculado. Sin esto,
        // cualquier estudiante de la institución podía empezar un examen activo
        // de otra aula con solo conocer su id. 404 y no 403: no se confirma que
        // exista.
        if (!Exam::query()->whereKey($exam->getKey())->asignadoAlAulaDe($user)->exists()) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        // RN: examen startable (activo + ventana) — fuera de transacción, no modifica datos
        try {
            $rules->assertExamIsStartable($exam);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        // Transacción con lock para evitar race condition si el estudiante
        // lanza dos requests simultáneos (doble clic, re-submit del navegador)
        try {
            $resultado = DB::transaction(function () use ($exam, $user, $rules, $student) {
                // Bloquear fila del estudiante → serializa starts concurrentes del mismo usuario
                Student::where('user_id', $user->id)->lockForUpdate()->firstOrFail();

                $intentos = ExamAttempt::where('exam_id', $exam->id)
                    ->where('student_user_id', $user->id)
                    ->get();

                /*
                 | Un solo intento en curso a la vez. Antes `start` solo contaba los
                 | intentos YA ENTREGADOS, así que con `max_attempts = 1` se podían
                 | abrir los que se quisieran y entregarlos todos: la nota más alta
                 | de N. Si hay uno abierto y aún dentro de plazo, no se crea otro:
                 | se devuelve cuál es para que el cliente lo retome.
                 */
                $enCurso = $intentos->first(function (ExamAttempt $a) use ($rules, $exam, $student) {
                    if ($a->submitted_at) {
                        return false;
                    }

                    try {
                        $rules->assertAttemptIsSubmittable($exam, $a, $student);

                        return true;
                    } catch (\RuntimeException) {
                        return false;   // vencido sin entregar
                    }
                });

                if ($enCurso) {
                    return ['intento' => $enCurso, 'nuevo' => false];
                }

                // Gastan turno los entregados Y los abandonados (abiertos que ya
                // vencieron): si no, bastaba abrir, mirar las preguntas y empezar
                // de nuevo sin consumir el intento.
                $usados = $intentos->count();

                $rules->assertAttemptsAvailable($exam, $usados);

                $intento = ExamAttempt::create([
                    'exam_id'         => $exam->id,
                    'student_user_id' => $user->id,
                    'attempt_number'  => $usados + 1,
                    'started_at'      => now(),
                    'submitted_at'    => null,
                    'score'           => 0,
                    'max_score'       => (float) $exam->questions()->sum('points'),
                    'grade_status'    => 'pending',
                ]);

                return ['intento' => $intento, 'nuevo' => true];
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        if (!$resultado['nuevo']) {
            return response()->json([
                'message' => 'Ya tienes un intento en curso de este examen: retómalo.',
                'data'    => $resultado['intento'],
            ], 409);
        }

        return response()->json(['data' => $resultado['intento']], 201);
    }

    /**
     * Enviar intento (submit)
     *
     * Reglas ajustadas a triggers:
     * - multiple_choice / true_false => selected_option_ids requerido y EXACTAMENTE 1 id
     * - short_answer => answer_text requerido (y NO opciones)
     */
    public function submit(
        Request $request,
        Exam $exam,
        ExamAttempt $attempt,
        ExamAttemptRulesService $rules,
        ExamGradingService $grading,
        StudentProgressService $progressService,
        AiRecommendationService $aiService
    ) {
        $user = $request->user();

        // Seguridad: intento del usuario y del examen
        if ($attempt->exam_id !== $exam->id || $attempt->student_user_id !== $user->id) {
            return response()->json(['message' => 'Intento no válido'], 404);
        }

        $student = Student::where('user_id', $user->id)->first();

        // RN: intentos submittable (pasa Student para aplicar adecuación curricular)
        try {
            $rules->assertAttemptIsSubmittable($exam, $attempt, $student);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $data = $request->validate([
            'answers' => ['present', 'array'],
            'answers.*.question_id' => ['required', 'uuid'],
            'answers.*.answer_text' => ['nullable', 'string', 'max:4000'],
            'answers.*.selected_option_ids' => ['nullable', 'array'],
            'answers.*.selected_option_ids.*' => ['integer'],
        ]);

        // ✅ Validación lógica contra tipos reales del examen (para no depender del trigger)
        $questions = Question::query()
            ->where('exam_id', $exam->id)
            ->with('options')
            ->get()
            ->keyBy('id');

        if ($questions->isEmpty()) {
            return response()->json(['message' => 'El examen no tiene preguntas'], 409);
        }

        $byQuestion = collect($data['answers'])->keyBy('question_id');

        $errors = [];

        foreach ($questions as $qid => $q) {
            $payload = $byQuestion->get($qid);

            // Si no viene, lo dejamos pasar (se guardará en blanco y quedará incorrecto),
            // pero si querés obligar a responder TODO, lo convertimos en error aquí.
            if (!$payload) {
                continue;
            }

            $type = $q->question_type->value;
            $answerText = $payload['answer_text'] ?? null;
            $selected = $payload['selected_option_ids'] ?? null;

            if (in_array($type, ['multiple_choice', 'true_false'], true)) {
                // Debe traer EXACTAMENTE 1 selección
                if (!is_array($selected) || count($selected) !== 1) {
                    $errors["answers.$qid.selected_option_ids"] = [
                        "Para {$type} debes enviar selected_option_ids con EXACTAMENTE 1 opción.",
                    ];
                }

                // No debe traer answer_text
                if (!empty($answerText) && trim((string) $answerText) !== '') {
                    $errors["answers.$qid.answer_text"] = [
                        "Para {$type} no se permite answer_text. Usa selected_option_ids.",
                    ];
                }
            }

            if ($type === 'short_answer') {
                // Debe traer texto
                if ($answerText === null || trim((string) $answerText) === '') {
                    $errors["answers.$qid.answer_text"] = [
                        "Para short_answer debes enviar answer_text.",
                    ];
                }

                // No debe traer opciones
                if (is_array($selected) && count($selected) > 0) {
                    $errors["answers.$qid.selected_option_ids"] = [
                        "Para short_answer no se permite selected_option_ids.",
                    ];
                }
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        // Ejecutar todo en transacción
        try {
        $result = DB::transaction(function () use (
            $exam,
            $attempt,
            $data,
            $questions,
            $grading,
            $progressService,
            $aiService,
            $rules
        ) {
            // Se vuelve a comprobar el máximo de intentos: `start` ya impide abrir de
            // más (un solo intento en curso, bajo bloqueo), pero esta es la última
            // puerta antes de que se guarde una nota. Es UNA consulta: no lleva
            // bloqueo propio porque, con esa regla, un alumno nunca tiene dos
            // intentos abiertos que entregar a la vez.
            $entregados = ExamAttempt::where('exam_id', $exam->id)
                ->where('student_user_id', $attempt->student_user_id)
                ->whereNotNull('submitted_at')
                ->count();

            $rules->assertAttemptsAvailable($exam, $entregados);

            // 1) Calificar intento + guardar respuestas. Las preguntas viajan ya
            // cargadas: se acaban de leer arriba para validar (O3).
            ['attempt' => $gradedAttempt, 'respuestas' => $respuestas] = $grading->calificar($exam, $attempt, $data['answers'], $questions);

            // El examen ya lo resolvió el binding de ruta: las recomendaciones
            // no tienen por qué volver a pedirlo.
            $gradedAttempt->setRelation('exam', $exam);

            // 2) Recalcular progreso (promedio por materia) si el examen tiene subject_id
            $progress = null;
            if (!empty($exam->subject_id)) {
                $progress = $progressService->recalcFromAttempts(
                    $gradedAttempt->student_user_id,
                    $exam->subject_id
                );
            }

            // 3) Generar recomendaciones
            $recommendations = $aiService->generateFromAttempt($gradedAttempt, $respuestas, $questions);

            return [
                'attempt' => $gradedAttempt,
                'progress' => $progress,
                'recommendations' => $recommendations,
            ];
        });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'data' => [
                'attempt' => $result['attempt'],
                'display_score' => $result['attempt']->display_score,
                'percentage' => $result['attempt']->percentage,
                'progress' => $result['progress'],
                'recommendations' => $result['recommendations'],
            ],
        ]);
    }

    /**
     * Ver un intento (con respuestas)
     */
    public function show(string $exam, string $attempt, Request $request)
    {
        $user = $request->user();

        // El intento (que sea del alumno y de ese examen) con su examen unido, en UNA consulta
        // en vez de los dos bindings de ruta; y las respuestas con sus preguntas y opciones en
        // otra (`RespuestasEnLinea`), no cuatro. Con la base remota cada viaje cuesta ~0,4 s.
        $attempt = RelacionesEnLinea::unir(
            ExamAttempt::query()
                ->where('exam_attempts.student_user_id', $user->id)
                ->where('exam_attempts.exam_id', $exam),
            ['exam' => ['exams', 'exam_id', Exam::COLUMNAS]]
        )->where('exam_attempts.id', $attempt)->first();

        if ($attempt === null) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        RelacionesEnLinea::hidratar([$attempt], ['exam' => Exam::class]);
        $exam = $attempt->exam;
        // El examen solo hace falta para decidir la revisión: no va en la respuesta.
        $attempt->unsetRelation('exam');

        $attempt->setRelation('answers', RespuestasEnLinea::delIntento($attempt->id));

        // `is_correct` y `correct_answer_text` van ocultos por defecto en los
        // modelos, así que aquí no hace falta filtrarlos: al estudiante nunca
        // se le revelan (este endpoint es solo para él, ver el guard de arriba).
        //
        // Lo que sí hay que gobernar es la corrección de SUS respuestas. Antes
        // se devolvía siempre, incluso con el intento en curso y aunque el
        // docente hubiera desactivado la revisión. Con `max_attempts > 1` eso
        // era filtrar las respuestas de cara al intento siguiente.
        $entregado = $attempt->submitted_at !== null;
        $puedeRevisar = $entregado && $exam->allow_review_after_submission;

        if (!$puedeRevisar) {
            $attempt->answers->each->makeHidden([
                'is_correct',
                'points_awarded',
                'correct_answer_snapshot',
                'explanation',
            ]);
        }

        return response()->json([
            'data' => $attempt,
            'meta' => [
                'submitted'    => $entregado,
                'review_shown' => $puedeRevisar,
            ],
        ]);
    }

    public function pause(Request $request, Exam $exam, ExamAttempt $attempt)
    {
        $user = $request->user();

        if ($attempt->exam_id !== $exam->id || $attempt->student_user_id !== $user->id) {
            return response()->json(['message' => 'Intento no válido'], 404);
        }

        if ($attempt->submitted_at) {
            return response()->json(['message' => 'El intento ya fue enviado'], 409);
        }

        if ($attempt->paused_at) {
            return response()->json(['message' => 'El intento ya está pausado'], 409);
        }

        if ((int) $attempt->total_paused_seconds >= (int) config('academic.exam.max_pause_seconds')) {
            return response()->json(['message' => 'Ya agotaste el tiempo de pausa permitido para este intento'], 409);
        }

        $attempt->update(['paused_at' => now()]);

        return response()->json(['data' => $attempt->fresh()]);
    }

    public function resume(Request $request, Exam $exam, ExamAttempt $attempt, ExamAttemptRulesService $rules)
    {
        $user = $request->user();

        if ($attempt->exam_id !== $exam->id || $attempt->student_user_id !== $user->id) {
            return response()->json(['message' => 'Intento no válido'], 404);
        }

        if ($attempt->submitted_at) {
            return response()->json(['message' => 'El intento ya fue enviado'], 409);
        }

        if (!$attempt->paused_at) {
            return response()->json(['message' => 'El intento no está pausado'], 409);
        }

        // Se acredita la pausa hasta el tope configurable: reanudar tras dos horas
        // no da dos horas más de examen. (`pausaAcreditada` suma la pausa en curso.)
        $attempt->update([
            'total_paused_seconds' => $rules->pausaAcreditada($attempt),
            'paused_at'            => null,
        ]);

        return response()->json(['data' => $attempt->fresh()]);
    }

    /**
     * Recomendaciones de un intento, y disparador del análisis de IA (D1).
     *
     * Es el endpoint que el alumno abre al consultar sus resultados, y la
     * **primera** vez que lo hace encola `GenerateAiRecommendations`. No se
     * encola en la entrega a propósito: el pico real del sistema es una clase
     * entera entregando a la vez, y así solo se paga API por quien de verdad va
     * a leer el análisis.
     *
     * `status` dice qué está mirando el alumno:
     *   - `preparing` → lo que ve son plantillas; el análisis viene en camino
     *   - `ready`     → ya son las del modelo
     *   - `failed`    → el modelo no respondió tras los reintentos; se queda con
     *                   las plantillas y puede pedir «regenerar» a mano
     *   - `null`      → no hay análisis encolado ni lo habrá (examen sin materia)
     *
     * El encolado va con un UPDATE condicionado al estado nulo: si el alumno
     * abre los resultados, recarga y vuelve a abrirlos, solo la primera llamada
     * se lleva la fila y solo esa encola. Sin eso, cada recarga sería una
     * llamada a OpenAI más.
     */
    public function recommendations(Request $request, string $attempt)
    {
        $user = $request->user();

        // El intento con su examen y el perfil del alumno, en UNA consulta (antes: el binding,
        // el examen y el alumno por separado). Con la base remota cada viaje cuesta ~0,4 s.
        $attempt = RelacionesEnLinea::unir(ExamAttempt::query(), [
            'exam'   => ['exams', 'exam_id', Exam::COLUMNAS],
            'alumno' => ['students', 'student_user_id', Student::COLUMNAS, null, 'user_id'],
        ])->where('exam_attempts.id', $attempt)->first() ?? abort(404);

        RelacionesEnLinea::hidratar([$attempt], ['exam' => Exam::class, 'alumno' => Student::class]);
        $alumno = $attempt->getRelation('alumno');
        $attempt->unsetRelation('alumno');

        if ($attempt->student_user_id !== $user->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        if (!$attempt->submitted_at) {
            return response()->json(['message' => 'El intento aún no ha sido enviado'], 409);
        }

        if ($attempt->exam?->subject_id && $attempt->ai_recommendations_status === null) {
            $encolado = ExamAttempt::query()
                ->whereKey($attempt->id)
                ->whereNull('ai_recommendations_status')
                ->update(['ai_recommendations_status' => AiRecommendationsStatus::Preparing->value]);

            if ($encolado === 1) {
                GenerateAiRecommendations::dispatch($attempt->id);
                $attempt->ai_recommendations_status = AiRecommendationsStatus::Preparing;
            } else {
                $attempt->refresh();
            }
        }

        $recomendaciones = AiRecommendation::query()
            ->where('attempt_id', $attempt->id)
            ->orderBy('generated_at')
            ->get();

        // El vídeo de apoyo que el docente puso en el examen, para el alumnado
        // visual o auditivo. El examen ya está cargado; solo cuesta leer el estilo.
        $estilo = $alumno?->learning_style;

        return response()->json([
            'data' => [
                'status'          => $attempt->ai_recommendations_status,
                'recommendations' => $recomendaciones,
                'video'           => app(FormatoPorEstilo::class)->videoPara($estilo, $attempt->exam, $alumno),
            ],
        ]);
    }

    public function regenerateRecommendations(
        Request $request,
        ExamAttempt $attempt,
        AiRecommendationService $aiService
    ) {
        $user = $request->user();

        // Solo el estudiante dueño del intento puede regenerar
        if ($attempt->student_user_id !== $user->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // Debe estar enviado
        if (!$attempt->submitted_at) {
            return response()->json(['message' => 'El intento aún no ha sido enviado'], 409);
        }

        // Necesitamos subject_id para guardar recomendaciones
        $attempt->load(['exam']);
        $subjectId = $attempt->exam?->subject_id;

        if (!$subjectId) {
            return response()->json(['message' => 'El examen no tiene materia asociada'], 409);
        }

        // Límite: la generación automática + 3 regeneraciones a mano.
        //
        // Se cuenta por `attempt_id`, que existe desde la migración del 13/09.
        // Cada llamada —la entrega, el job de IA y cada regeneración— escribe su
        // lote con un mismo `generated_at`, así que contar instantes distintos
        // cuenta generaciones y no filas.
        //
        // Antes no había `attempt_id` y el lote se acotaba por (estudiante,
        // examen, materia) más una ventana `generated_at >= submitted_at`. Eso
        // ya era mejor que el `ceil($total / 4)` original —que dividía entre 4
        // unos lotes de 1 o 2 filas, y dejaba al segundo intento de un examen
        // nacer con el cupo del primero gastado—, pero seguía dependiendo de
        // relojes. Ahora la pertenencia al intento es un dato, no una inferencia.
        //
        // `generated_at` tiene precisión de segundo: dos generaciones dentro del
        // mismo segundo cuentan como una. El error va del lado permisivo y hace
        // falta un cronometraje imposible a mano.
        $MAX_REGENS = 3;

        $generacionesPrevias = AiRecommendation::where('attempt_id', $attempt->id)
            ->distinct()
            ->count('generated_at');

        if ($generacionesPrevias >= 1 + $MAX_REGENS) {
            return response()->json([
                'message' => 'Límite de regeneraciones alcanzado para este intento',
            ], 429);
        }

        // Generar y guardar nuevas recomendaciones
        $created = $aiService->regenerateForAttempt($attempt, $user->id);

        // Misma regla que el job: en cuanto hay análisis real, las plantillas de
        // ese intento se retiran. Si no, el alumno acumula tarjetas genéricas
        // debajo de las buenas cada vez que pulsa regenerar.
        $hayAnalisis = collect($created)
            ->contains(fn (AiRecommendation $r) => $r->generated_by === AiGenerationSource::Ai);

        if ($hayAnalisis) {
            AiRecommendation::query()
                ->where('attempt_id', $attempt->id)
                ->where('generated_by', AiGenerationSource::Heuristic->value)
                ->delete();

            $attempt->forceFill([
                'ai_recommendations_status' => AiRecommendationsStatus::Ready,
            ])->save();
        }

        return response()->json([
            'data' => $created,
        ], 201);
    }
}
