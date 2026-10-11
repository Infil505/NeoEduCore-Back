<?php

namespace Tests\Feature\AI;

use App\Models\Academic\Group;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Exams\Question;
use App\Models\Students\Student;
use App\Services\Students\StudentProgressService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * El tutor conoce los EXÁMENES del estudiante: cómo le fue y en qué temas falló.
 *
 * Antes solo sabía el % de dominio por materia, así que sus consejos eran tan
 * genéricos como esa cifra. Las garantías que importan son tres: usa los
 * resultados reales, no filtra la respuesta correcta ni el nombre, y se entera
 * de un examen nuevo sin esperar a que caduque su caché.
 */
class ContextoDeExamenesTest extends TestCase
{
    use ApiAuth;

    private const NOMBRE = 'Mariana Solís Vargas';
    private const SECRETO = 'RESPUESTA-CORRECTA-SECRETA';

    private Institution $centro;
    private User $alumno;
    private Group $aula;
    private Subject $matematicas;
    private Exam $examen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centro = Institution::factory()->create();
        $this->alumno = $this->signInStudent(['institution_id' => $this->centro->id, 'full_name' => self::NOMBRE]);
        Student::factory()->create(['user_id' => $this->alumno->id, 'institution_id' => $this->centro->id, 'grade' => 4]);

        $this->aula = Group::factory()->create(['institution_id' => $this->centro->id]);
        DB::table('group_students')->insert([
            'id' => (string) Str::uuid(), 'institution_id' => $this->centro->id, 'group_id' => $this->aula->id,
            'student_user_id' => $this->alumno->id, 'joined_at' => now(), 'left_at' => null,
        ]);

        $this->matematicas = Subject::factory()->create(['institution_id' => $this->centro->id, 'name' => 'Matemáticas']);
        // El estudiante lleva Matemáticas por su sección (el selector del tutor solo ofrece esas).
        $this->asignarDocente(User::factory()->teacher()->create(['institution_id' => $this->centro->id]), $this->aula->id, $this->matematicas->id);
        $this->examen = $this->examenEntregado('Prueba de fracciones', $this->matematicas, 5, 10);
    }

    /** Un examen entregado con dos preguntas: falla «Fracciones», acierta «Decimales». */
    private function examenEntregado(string $titulo, Subject $materia, float $score, float $max, ?User $alumno = null): Exam
    {
        $alumno ??= $this->alumno;

        $examen = Exam::factory()->create([
            'institution_id' => $this->centro->id, 'subject_id' => $materia->id, 'title' => $titulo,
            'status' => 'completed', 'created_by_teacher_id' => User::factory()->teacher()->create(['institution_id' => $this->centro->id])->id,
        ]);

        $fracciones = Question::factory()->create([
            'institution_id' => $this->centro->id, 'exam_id' => $examen->id, 'question_type' => 'short_answer',
            'question_text' => 'Compara 1/2 y 1/3', 'topic' => 'Fracciones', 'indicator' => 'Compara fracciones',
            'correct_answer_text' => self::SECRETO, 'order_index' => 1,
        ]);
        $decimales = Question::factory()->create([
            'institution_id' => $this->centro->id, 'exam_id' => $examen->id, 'question_type' => 'short_answer',
            'question_text' => 'Suma 0,5 y 0,25', 'topic' => 'Decimales', 'correct_answer_text' => self::SECRETO, 'order_index' => 2,
        ]);

        $intento = ExamAttempt::create([
            'institution_id' => $this->centro->id, 'exam_id' => $examen->id, 'student_user_id' => $alumno->id,
            'attempt_number' => 1, 'started_at' => now()->subHour(), 'submitted_at' => now()->subMinutes(30),
            'score' => $score, 'max_score' => $max, 'grade_status' => 'graded',
        ]);

        foreach ([[$fracciones, false, 'las dos iguales'], [$decimales, true, '0,75']] as [$pregunta, $ok, $texto]) {
            DB::table('student_answers')->insert([
                'id' => (string) Str::uuid(), 'institution_id' => $this->centro->id, 'attempt_id' => $intento->id,
                'question_id' => $pregunta->id, 'answer_text' => $texto, 'is_correct' => $ok, 'points_awarded' => $ok ? 5 : 0,
                'review_status' => 'auto_graded', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $examen;
    }

    private function fingir(int $respuestas = 1): void
    {
        OpenAI::fake(array_fill(0, $respuestas, CreateResponse::fake([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Vamos a repasarlo.']]],
        ])));
    }

    /** Todo lo enviado al modelo en la petición n.º $indice, como texto. */
    private function enviado(int $indice = 0): string
    {
        $peticiones = [];
        OpenAI::assertSent(Chat::class, function (string $m, array $p) use (&$peticiones) {
            $peticiones[] = json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return true;
        });

        return $peticiones[$indice] ?? '';
    }

    public function test_el_tutor_sabe_cuantas_preguntas_fallo_y_se_le_manda_revisar_primero_los_resultados(): void
    {
        $this->fingir();

        $this->postJson('/api/ai/tutor/chat', ['message' => '¿En qué debo mejorar?'])->assertOk();

        $enviado = $this->enviado();
        $this->assertStringContainsString('Falló 1 de 2 preguntas', $enviado);
        // La pregunta concreta y lo que contestó, aunque no se abra el examen.
        $this->assertStringContainsString('Falló: «Compara 1/2 y 1/3»', $enviado);
        $this->assertStringContainsString('respondió «las dos iguales»', $enviado);
        $this->assertStringNotContainsString('Suma 0,5 y 0,25', $enviado, 'Decimales estaba bien.');
        $this->assertStringContainsString('ANTES de responder, revisa estos resultados', $enviado);
        $this->assertStringNotContainsString(self::SECRETO, $enviado);
    }

    public function test_al_entrar_por_una_materia_el_tutor_ve_su_ultimo_examen_de_ella(): void
    {
        $this->fingir();

        $this->postJson('/api/ai/tutor/chat', [
            'message'    => 'Ayúdame con mates',
            'subject_id' => $this->matematicas->id,
        ])->assertOk();

        $enviado = $this->enviado();
        $this->assertStringContainsString('Examen sobre el que se conversa', $enviado);
        $this->assertStringContainsString('Compara 1/2 y 1/3', $enviado);
        $this->assertStringNotContainsString(self::SECRETO, $enviado);
    }

    public function test_el_tutor_recibe_los_resultados_y_los_temas_donde_fallo(): void
    {
        $this->fingir();

        $this->postJson('/api/ai/tutor/chat', ['message' => 'No entiendo las fracciones'])->assertOk();

        $enviado = $this->enviado();
        $this->assertStringContainsString('Exámenes recientes', $enviado);
        $this->assertStringContainsString('Matemáticas', $enviado);
        $this->assertStringContainsString('Prueba de fracciones', $enviado);
        $this->assertStringContainsString('50 %', $enviado);
        $this->assertStringContainsString('Fallos en: Fracciones', $enviado);
        $this->assertStringNotContainsString('Fallos en: Fracciones, Decimales', $enviado, 'Decimales estaba bien.');
    }

    public function test_nunca_viaja_la_respuesta_correcta_ni_el_nombre(): void
    {
        $this->fingir();

        $this->postJson('/api/ai/tutor/chat', ['message' => 'Ayuda con mi examen'])->assertOk();

        $enviado = $this->enviado();
        $this->assertStringNotContainsString(self::SECRETO, $enviado);
        $this->assertStringNotContainsString('Mariana', $enviado);
        $this->assertStringNotContainsString('Solís', $enviado);
    }

    public function test_los_examenes_por_presentar_aparecen_con_su_fecha(): void
    {
        $ciencias = Subject::factory()->create(['institution_id' => $this->centro->id, 'name' => 'Ciencias']);
        $pendiente = Exam::factory()->create([
            'institution_id' => $this->centro->id, 'subject_id' => $ciencias->id, 'title' => 'Examen final de ciencias',
            'status' => 'active', 'max_attempts' => 1, 'available_from' => now()->subDay(), 'available_until' => now()->addDays(5),
        ]);
        $pendiente->syncGroups([$this->aula->id]);
        $this->fingir();

        $this->postJson('/api/ai/tutor/chat', ['message' => 'Qué me falta'])->assertOk();

        $enviado = $this->enviado();
        $this->assertStringContainsString('Exámenes por presentar', $enviado);
        $this->assertStringContainsString('Examen final de ciencias', $enviado);
        $this->assertStringContainsString('de Ciencias', $enviado);
    }

    public function test_sin_examenes_el_prompt_queda_como_antes(): void
    {
        DB::table('exam_attempts')->where('student_user_id', $this->alumno->id)->delete();
        $this->fingir();

        $this->postJson('/api/ai/tutor/chat', ['message' => 'Hola'])->assertOk();

        $this->assertStringNotContainsString('Exámenes recientes', $this->enviado());
    }

    public function test_hablando_de_un_examen_concreto_el_tutor_ve_lo_que_fallo_sin_la_respuesta_correcta(): void
    {
        $this->fingir();

        $this->postJson('/api/ai/tutor/chat', ['message' => 'Explícame mis errores', 'exam_id' => $this->examen->id])->assertOk();

        $enviado = $this->enviado();
        $this->assertStringContainsString('Examen sobre el que se conversa', $enviado);
        $this->assertStringContainsString('Compara 1/2 y 1/3', $enviado);
        $this->assertStringContainsString('tema Fracciones', $enviado);
        $this->assertStringContainsString('indicador Compara fracciones', $enviado);
        $this->assertStringContainsString('las dos iguales', $enviado);        // lo que contestó
        $this->assertStringNotContainsString('Suma 0,5 y 0,25', $enviado);     // la que acertó no se detalla
        $this->assertStringNotContainsString(self::SECRETO, $enviado);
        $this->assertStringContainsString('REGLAS ESTRICTAS', $enviado);
        $this->assertStringContainsString('OTROS números', $enviado);
    }

    private function respuestaDelModelo(string $texto): CreateResponse
    {
        return CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => $texto]]]]);
    }

    public function test_el_examen_mas_reciente_va_marcado_como_titular(): void
    {
        $this->fingir();

        $this->postJson('/api/ai/tutor/chat', ['message' => '¿En qué debo mejorar?'])->assertOk();

        $enviado = $this->enviado();
        $this->assertStringContainsString('(el más reciente)', $enviado);
        $this->assertStringContainsString('EMPIEZA por el examen más reciente', $enviado);
        // Pedir la respuesta no se contesta con un «no»: se enseña como en práctica.
        $this->assertStringContainsString('cambia a ENSEÑAR como en el modo práctica', $enviado);
    }

    public function test_si_el_modelo_revela_la_respuesta_se_le_pide_reescribirla(): void
    {
        OpenAI::fake([
            $this->respuestaDelModelo('Es fácil: la respuesta es ' . self::SECRETO . '.'),
            $this->respuestaDelModelo('Piensa en otro ejemplo con otros números. ¿Qué haces primero?'),
        ]);

        $this->postJson('/api/ai/tutor/chat', ['message' => 'Dime la respuesta', 'exam_id' => $this->examen->id])
            ->assertOk()
            ->assertJsonPath('data.reply', 'Piensa en otro ejemplo con otros números. ¿Qué haces primero?');

        $this->assertStringContainsString('reveló el resultado', $this->enviado(1));
        $this->assertStringContainsString('MODO: enseñanza, como en práctica', $this->enviado(1));
        $this->assertStringContainsString('ejemplo RESUELTO', $this->enviado(1));
    }

    public function test_si_insiste_en_revelarla_el_alumno_recibe_el_texto_fijo(): void
    {
        OpenAI::fake([
            $this->respuestaDelModelo('Claro: la respuesta es ' . self::SECRETO . '.'),
            $this->respuestaDelModelo('Para comparar 1/2 y 1/3 la solución es ' . self::SECRETO . '.'),
        ]);

        $r = $this->postJson('/api/ai/tutor/chat', ['message' => 'Dime la respuesta', 'exam_id' => $this->examen->id])->assertOk();

        // Reserva calculada sin modelo: su examen más reciente, la nota y el tema por el que empezar.
        $this->assertStringContainsString('En tu examen más reciente de Matemáticas («', $r->json('data.reply'));
        $this->assertStringContainsString('50 %', $r->json('data.reply'));
        $this->assertStringContainsString('¿Empezamos por «Fracciones»', $r->json('data.reply'));
        $this->assertStringNotContainsString(self::SECRETO, (string) $r->getContent());
    }

    public function test_una_respuesta_sin_la_solucion_pasa_sin_segunda_llamada(): void
    {
        OpenAI::fake([$this->respuestaDelModelo('Compara usando el mismo denominador.')]);

        $this->postJson('/api/ai/tutor/chat', ['message' => 'Ayúdame', 'exam_id' => $this->examen->id])
            ->assertOk()->assertJsonPath('data.reply', 'Compara usando el mismo denominador.');
    }

    public function test_el_modo_practica_con_ejercicios_distintos_pasa(): void
    {
        // Sus respuestas al final pueden coincidir con valores del examen sin ser el mismo ejercicio.
        OpenAI::fake([$this->respuestaDelModelo('Ejercicio 1... Respuestas: ' . self::SECRETO)]);

        $this->postJson('/api/ai/tutor/chat', ['message' => 'Quiero practicar', 'mode' => 'practice', 'topic' => 'Fracciones'])
            ->assertOk()->assertJsonPath('data.reply', 'Ejercicio 1... Respuestas: ' . self::SECRETO);
    }

    public function test_un_ejercicio_de_practica_que_es_la_pregunta_del_examen_se_reescribe(): void
    {
        // Lo que ocurrió en la prueba real: la pregunta del examen colada como «ejercicio 1» con su respuesta.
        OpenAI::fake([
            $this->respuestaDelModelo('Ejercicio 1: Compara 1/2 y 1/3. Respuestas: 1. ' . self::SECRETO),
            $this->respuestaDelModelo('Ejercicio 1: Compara 2/5 y 1/4. Respuestas: 1. 2/5'),
        ]);

        $this->postJson('/api/ai/tutor/chat', ['message' => 'Quiero practicar', 'mode' => 'practice', 'topic' => 'Fracciones'])
            ->assertOk()
            ->assertJsonPath('data.reply', 'Ejercicio 1: Compara 2/5 y 1/4. Respuestas: 1. 2/5');

        $this->assertStringContainsString('reveló el resultado', $this->enviado(1));
    }

    public function test_la_directiva_de_practica_pide_numeros_y_situaciones_distintos(): void
    {
        $this->fingir();

        $this->postJson('/api/ai/tutor/chat', ['message' => 'Quiero practicar', 'mode' => 'practice', 'topic' => 'Fracciones'])->assertOk();

        $this->assertStringContainsString('DISTINTOS a los de sus exámenes', $this->enviado());
    }

    public function test_la_barrera_detecta_la_solucion_de_una_pregunta_y_no_los_ejemplos(): void
    {
        $revela = new \ReflectionMethod(\App\Services\AI\AiTutorService::class, 'revela');
        $revela->setAccessible(true);
        $servicio = app(\App\Services\AI\AiTutorService::class);

        $protegidas = [
            ['respuesta' => '5/8', 'enunciado' => 'Marta comió 2/8 de una pizza y Luis 3/8. ¿Cuánta pizza comieron en total?'],
            ['respuesta' => '1/2', 'enunciado' => 'Escribe 0,5 como fracción'],
            ['respuesta' => '3/4', 'enunciado' => '¿Cuál es mayor: 3/4 o 2/3?'],
        ];

        $fugas = [
            'Entonces 2/8 + 3/8 es 5/8.',                       // nombra la pregunta con sus números
            'La respuesta es 5/8',                              // dice «respuesta»
            'Comieron 5/8 de la pizza.',                        // nombra la pregunta con sus palabras
            "Sumas y listo.\nRecuerda que 0,5 es lo mismo que 1/2.",
            'El resultado es 1/2',
            'Suma los numeradores: 2/8 + 3/8.',                 // el cálculo hecho con sus números, sin decir el resultado
            'Ahora suma 2/8 y 3/8 y dime qué te da.',
            'Suma 2/8 y 3/8 con el mismo denominador.',         // operandos y operación: ya es el ejercicio resuelto
        ];
        foreach ($fugas as $texto) {
            $this->assertTrue($revela->invoke($servicio, $texto, $protegidas), "Debía detectar: {$texto}");
        }

        // Respuestas en palabras: cualquier mención suelta cuenta (no hace falta nombrar la pregunta).
        $enPalabras = [
            ['respuesta' => 'evaporación', 'enunciado' => '¿Cómo se llama el cambio de agua líquida a vapor?'],
            ['respuesta' => 'corazón', 'enunciado' => '¿Qué órgano bombea la sangre por el cuerpo?'],
        ];
        $this->assertTrue($revela->invoke($servicio, 'Ese proceso se llama evaporación.', $enPalabras));
        $this->assertTrue($revela->invoke($servicio, 'Piensa en el Corazón, que late sin parar.', $enPalabras));
        $this->assertFalse($revela->invoke($servicio, 'Piensa en cuando se seca la ropa al sol: ¿qué le pasa al agua?', $enPalabras));
        $this->assertTrue($revela->invoke($servicio, 'El agua empieza a evaporarse al calentarla.', $enPalabras), 'Una derivada también la regala.');
        $this->assertTrue($revela->invoke($servicio, 'Se transformará por evaporación.', $enPalabras));
        $this->assertFalse($revela->invoke($servicio, 'Cuando calientas la olla, el agua líquida pasa a ser gas. ¿Cómo lo llamarías tú?', $enPalabras));
        $this->assertFalse($revela->invoke($servicio, 'Piensa en una olla con agua hirviendo y en cómo se ve el aire encima.', $enPalabras));

        $limpias = [
            'Si comparas 1/2 y 3/4, ¿cuál es mayor?',           // ejemplos con valores que son resultado de otras
            '15/8 no es lo que buscas, y 5/80 tampoco.',        // el valor dentro de otro número no cuenta
            'Con 1/4 y 1/4 la suma da 2/4.',
            'Recuerda que Marta comió 2/8 y Luis 3/8.',         // nombrar la pregunta está permitido
            'Te preguntaron cuál es mayor entre 3/4 y 2/3.',    // decir cuál falló es parte de la ayuda
            'Piensa en 0,5 como la mitad de algo. ¿Cómo la escribirías?',
        ];
        foreach ($limpias as $texto) {
            $this->assertFalse($revela->invoke($servicio, $texto, $protegidas), "No debía detectar: {$texto}");
        }
    }

    public function test_ensenando_la_palabra_puede_salir_en_otros_casos_pero_no_con_la_pregunta_del_examen(): void
    {
        $revela = new \ReflectionMethod(\App\Services\AI\AiTutorService::class, 'revela');
        $revela->setAccessible(true);
        $servicio = app(\App\Services\AI\AiTutorService::class);
        $protegidas = [['respuesta' => 'evaporación', 'enunciado' => '¿Cómo se llama el cambio de agua líquida a vapor?']];

        // Enseñar con otros casos: permitido (modo práctica / reescritura = comparación por texto completo).
        $this->assertFalse($revela->invoke($servicio, "Ejercicios:
1. Si dejas la leche al sol, ¿qué le pasará?
Respuestas:
1. La leche se evaporará y disminuirá.", $protegidas, true));
        $this->assertFalse($revela->invoke($servicio, 'El charco se irá secando porque el agua se evapora con el calor.', $protegidas, true));

        // La pregunta del examen repetida y su respuesta (en la misma frase o en la lista de respuestas): fuga.
        $this->assertTrue($revela->invoke($servicio, 'El cambio de agua líquida a vapor se llama evaporación.', $protegidas, true));
        $this->assertTrue($revela->invoke($servicio, 'Cuando el agua líquida pasa a vapor ocurre la evaporación.', $protegidas, true));

        // En una respuesta normal (no enseñanza) cualquier mención suelta cuenta.
        $this->assertTrue($revela->invoke($servicio, 'La leche se evaporará al sol.', $protegidas, false));
    }

    public function test_solo_se_protegen_las_preguntas_falladas_o_por_presentar(): void
    {
        $protegidas = app(\App\Services\AI\ContextoDeExamenes::class)
            ->respuestasProtegidas($this->alumno->id, $this->centro->id);

        // En setUp: «Compara 1/2 y 1/3» (falló) y «Suma 0,5 y 0,25» (acertó), ambas con la misma respuesta.
        $enunciados = array_column($protegidas, 'enunciado');
        $this->assertContains('Compara 1/2 y 1/3', $enunciados);
        $this->assertNotContains('Suma 0,5 y 0,25', $enunciados, 'Lo que acertó no hace falta ocultarlo.');
    }

    public function test_un_examen_ajeno_no_llega_ni_al_modelo(): void
    {
        $otroAlumno = User::factory()->student()->create(['institution_id' => $this->centro->id, 'status' => 'active']);
        Student::factory()->create(['user_id' => $otroAlumno->id, 'institution_id' => $this->centro->id]);
        $ajeno = $this->examenEntregado('Examen del compañero', $this->matematicas, 1, 10, $otroAlumno);
        $this->fingir();

        // El controlador ya exige que el examen sea del estudiante (422) y por
        // eso ni siquiera se llama al modelo; el detalle además filtra por alumno.
        $this->postJson('/api/ai/tutor/chat', ['message' => 'Dime del examen', 'exam_id' => $ajeno->id])->assertStatus(422);

        OpenAI::assertNothingSent();
    }

    public function test_un_examen_nuevo_llega_al_tutor_sin_esperar_a_que_caduque_la_cache(): void
    {
        $this->fingir(2);
        $this->postJson('/api/ai/tutor/chat', ['message' => 'Primero'])->assertOk();

        // Entrega otro examen y se recalcula el progreso, como al finalizar un intento.
        $ciencias = Subject::factory()->create(['institution_id' => $this->centro->id, 'name' => 'Ciencias']);
        $this->examenEntregado('Prueba de ciencias', $ciencias, 9, 10);
        app(StudentProgressService::class)->upsertProgress($this->alumno->id, $ciencias->id, 90);

        $this->postJson('/api/ai/tutor/chat', ['message' => 'Segundo'])->assertOk();

        $this->assertStringNotContainsString('Prueba de ciencias', $this->enviado(0));
        $this->assertStringContainsString('Prueba de ciencias', $this->enviado(1), 'El tutor siguió con el contexto viejo.');
    }

    public function test_el_diagnostico_tambien_habla_de_los_examenes(): void
    {
        $this->fingir();
        \App\Models\Students\StudentProgress::create([
            'institution_id' => $this->centro->id, 'student_user_id' => $this->alumno->id, 'subject_id' => $this->matematicas->id,
            'mastery_percentage' => 50, 'updated_at' => now(),
        ]);

        $this->getJson('/api/ai/tutor/diagnosis')->assertOk();

        $enviado = $this->enviado();
        $this->assertStringContainsString('Prueba de fracciones', $enviado);
        $this->assertStringContainsString('Fallos en: Fracciones', $enviado);
        $this->assertStringNotContainsString('Mariana', $enviado);
    }
}
