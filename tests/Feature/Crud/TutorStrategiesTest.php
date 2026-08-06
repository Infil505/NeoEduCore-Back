<?php

namespace Tests\Feature\Crud;

use App\Enums\AiRecommendationType;
use App\Models\AI\AiChatSession;
use App\Models\AI\AiRecommendation;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Students\Student;
use App\Services\Admin\ReportStrategyService;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Reporte de estrategias del tutor — requisito [740] del informe.
 *
 * El punto delicado no es agrupar recomendaciones, es **qué no debe salir**:
 * [175] prohíbe que el docente vea mensajes individuales del tutor, y la
 * decisión tomada el 05/08/2026 acota además al docente a los exámenes que él
 * creó. Casi todos los tests de aquí vigilan ese límite.
 */
class TutorStrategiesTest extends TestCase
{
    use ApiAuth;

    public function test_sections_cover_every_recommendation_type(): void
    {
        // Si alguien añade una categoría al enum y olvida la sección, las
        // recomendaciones de ese tipo desaparecerían del reporte en silencio.
        //
        // Se comparan como conjuntos: el orden de SECTIONS es el narrativo del
        // documento (fortalezas → a reforzar → acciones → recursos) y no tiene
        // por qué coincidir con el orden de declaración del enum.
        $delEnum = array_map(fn (AiRecommendationType $t) => $t->value, AiRecommendationType::cases());
        $deSecciones = array_keys(ReportStrategyService::SECTIONS);

        sort($delEnum);
        sort($deSecciones);

        $this->assertSame(
            $delEnum,
            $deSecciones,
            'ReportStrategyService::SECTIONS no cubre todos los AiRecommendationType'
        );
    }

    public function test_student_downloads_own_strategies_grouped_by_category(): void
    {
        $institution = Institution::factory()->create();
        $studentUser = $this->signInStudent(['institution_id' => $institution->id]);
        Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        $subject = Subject::factory()->create(['institution_id' => $institution->id]);

        foreach (['strength', 'weakness', 'action', 'resource'] as $type) {
            AiRecommendation::factory()->create([
                'institution_id' => $institution->id,
                'student_user_id' => $studentUser->id,
                'subject_id' => $subject->id,
                'recommendation_type' => $type,
                'recommendation_text' => "Texto de {$type}",
            ]);
        }

        $data = $this->getJson('/api/reports/students/me/strategies')->assertOk()->json('data');

        // Orden narrativo: fortalezas → a reforzar → acciones → recursos
        $this->assertSame(
            ['strength', 'weakness', 'action', 'resource'],
            collect($data['strategies'])->pluck('key')->all()
        );
        $this->assertSame(
            ['Fortalezas', 'Aspectos por reforzar', 'Acciones sugeridas', 'Recursos de apoyo'],
            collect($data['strategies'])->pluck('label')->all()
        );

        $this->assertSame(4, $data['totals']['total']);
        $this->assertSame(1, $data['totals']['weakness']);
        $this->assertFalse($data['truncated']);

        $seccion = collect($data['strategies'])->firstWhere('key', 'weakness');
        $this->assertSame('Texto de weakness', $seccion['items'][0]['text']);
        $this->assertSame($subject->name, $seccion['items'][0]['subject']);
    }

    /**
     * El límite duro de [175]: el historial de chat con el tutor no sale por
     * ningún reporte, ni siquiera en el del propio alumno.
     */
    public function test_chat_history_never_appears_in_the_strategies_report(): void
    {
        $institution = Institution::factory()->create();
        $studentUser = $this->signInStudent(['institution_id' => $institution->id]);
        Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        $subject = Subject::factory()->create(['institution_id' => $institution->id]);

        AiRecommendation::factory()->create([
            'institution_id' => $institution->id,
            'student_user_id' => $studentUser->id,
            'subject_id' => $subject->id,
            'recommendation_type' => 'action',
            'recommendation_text' => 'Practica fracciones equivalentes.',
        ]);

        // Sin factory: el modelo de sesiones de chat no tiene una y no hace
        // falta crearla solo para esto.
        AiChatSession::create([
            'institution_id' => $institution->id,
            'student_user_id' => $studentUser->id,
            'subject_id' => $subject->id,
            'messages' => [
                ['role' => 'user', 'content' => 'CONFIDENCIAL_MENSAJE_DEL_ALUMNO'],
                ['role' => 'assistant', 'content' => 'CONFIDENCIAL_RESPUESTA_DEL_TUTOR'],
            ],
        ]);

        $res = $this->getJson('/api/reports/students/me/strategies')->assertOk();

        $res->assertDontSee('CONFIDENCIAL_MENSAJE_DEL_ALUMNO');
        $res->assertDontSee('CONFIDENCIAL_RESPUESTA_DEL_TUTOR');
        $res->assertSee('Practica fracciones equivalentes.');
    }

    public function test_teacher_only_sees_strategies_from_their_own_exams(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);
        $otroDocente = User::factory()->teacher()->create(['institution_id' => $institution->id]);

        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        $subject = Subject::factory()->create(['institution_id' => $institution->id]);

        $examPropio = Exam::factory()->create([
            'institution_id' => $institution->id,
            'subject_id' => $subject->id,
            'created_by_teacher_id' => $teacher->id,
        ]);
        $examAjeno = Exam::factory()->create([
            'institution_id' => $institution->id,
            'subject_id' => $subject->id,
            'created_by_teacher_id' => $otroDocente->id,
        ]);

        foreach ([[$examPropio, 'VISIBLE_EXAMEN_PROPIO'], [$examAjeno, 'OCULTO_EXAMEN_AJENO']] as [$exam, $texto]) {
            AiRecommendation::factory()->create([
                'institution_id' => $institution->id,
                'student_user_id' => $studentUser->id,
                'subject_id' => $subject->id,
                'exam_id' => $exam->id,
                'recommendation_type' => 'weakness',
                'recommendation_text' => $texto,
            ]);
        }

        $res = $this->getJson("/api/reports/students/{$studentUser->id}/strategies")->assertOk();

        $res->assertSee('VISIBLE_EXAMEN_PROPIO');
        $res->assertDontSee('OCULTO_EXAMEN_AJENO');
        $this->assertSame(1, $res->json('data.totals.total'));
    }

    public function test_admin_sees_every_strategy_of_the_institution(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $docente = User::factory()->teacher()->create(['institution_id' => $institution->id]);
        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        $subject = Subject::factory()->create(['institution_id' => $institution->id]);
        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'subject_id' => $subject->id,
            'created_by_teacher_id' => $docente->id,
        ]);

        // Una con examen y otra sin él: el admin ve las dos
        AiRecommendation::factory()->create([
            'institution_id' => $institution->id,
            'student_user_id' => $studentUser->id,
            'subject_id' => $subject->id,
            'exam_id' => $exam->id,
            'recommendation_type' => 'strength',
        ]);
        AiRecommendation::factory()->create([
            'institution_id' => $institution->id,
            'student_user_id' => $studentUser->id,
            'subject_id' => $subject->id,
            'exam_id' => null,
            'recommendation_type' => 'action',
        ]);

        $data = $this->getJson("/api/reports/students/{$studentUser->id}/strategies")->assertOk()->json('data');

        $this->assertSame(2, $data['totals']['total']);
    }

    public function test_student_cannot_read_another_students_strategies(): void
    {
        $institution = Institution::factory()->create();

        $otroAlumno = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create([
            'user_id' => $otroAlumno->id,
            'institution_id' => $institution->id,
        ]);

        $studentUser = $this->signInStudent(['institution_id' => $institution->id]);
        Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        $this->getJson("/api/reports/students/{$otroAlumno->id}/strategies")->assertForbidden();
    }

    public function test_limit_caps_each_section_and_is_validated(): void
    {
        $institution = Institution::factory()->create();
        $studentUser = $this->signInStudent(['institution_id' => $institution->id]);
        Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        $subject = Subject::factory()->create(['institution_id' => $institution->id]);

        foreach (range(1, 5) as $i) {
            AiRecommendation::factory()->create([
                'institution_id' => $institution->id,
                'student_user_id' => $studentUser->id,
                'subject_id' => $subject->id,
                'recommendation_type' => 'weakness',
            ]);
        }

        $data = $this->getJson('/api/reports/students/me/strategies?limit=2')->assertOk()->json('data');

        $this->assertSame(2, $data['totals']['weakness']);
        $this->assertTrue($data['truncated']);

        $this->getJson('/api/reports/students/me/strategies?limit=0')->assertStatus(422);
        $this->getJson('/api/reports/students/me/strategies?limit=999')->assertStatus(422);
    }
}
