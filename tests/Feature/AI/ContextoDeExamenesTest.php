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
        $this->assertStringContainsString('no resuelvas la pregunta', $enviado);
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
