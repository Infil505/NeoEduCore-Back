<?php

namespace Tests\Feature\Crud;

use App\Models\AI\AiRecommendation;
use App\Models\Academic\Subject;
use App\Models\Admin\User;
use App\Models\Admin\Institution;
use App\Models\Exams\Exam;
use App\Models\Students\Student;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

class AiRecommendationsTest extends TestCase
{
    use ApiAuth;

    public function test_list_ai_recommendations(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $studentUser = User::factory()->student()->create([
            'institution_id' => $institution->id,
        ]);
        Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        $subject = Subject::factory()->create([
            'institution_id' => $institution->id,
        ]);

        AiRecommendation::factory()->create([
            'institution_id' => $institution->id,
            'student_user_id' => $studentUser->id,
            'subject_id' => $subject->id,
        ]);

        $res = $this->getJson('/api/ai-recommendations');

        $res->assertOk();
    }

    public function test_my_ai_recommendations(): void
    {
        $institution = Institution::factory()->create();
        
        $studentUser = User::factory()->student()->create([
            'institution_id' => $institution->id,
        ]);
        Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        $subject = Subject::factory()->create([
            'institution_id' => $institution->id,
        ]);

        AiRecommendation::factory()->create([
            'institution_id' => $institution->id,
            'student_user_id' => $studentUser->id,
            'subject_id' => $subject->id,
        ]);

        $this->actingAs($studentUser, 'sanctum');

        $res = $this->getJson('/api/ai-recommendations/me');

        $res->assertOk();
    }

    public function test_show_ai_recommendation(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $studentUser = User::factory()->student()->create([
            'institution_id' => $institution->id,
        ]);
        Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        $subject = Subject::factory()->create([
            'institution_id' => $institution->id,
        ]);

        // El teacher debe ser dueño del examen vinculado a la recomendación
        $exam = Exam::factory()->create([
            'institution_id'        => $institution->id,
            'created_by_teacher_id' => $teacher->id,
            'subject_id'            => $subject->id,
        ]);

        $recommendation = AiRecommendation::factory()->create([
            'institution_id'  => $institution->id,
            'student_user_id' => $studentUser->id,
            'subject_id'      => $subject->id,
            'exam_id'         => $exam->id,
        ]);

        $res = $this->getJson("/api/ai-recommendations/{$recommendation->id}");

        $res->assertOk();
    }

    public function test_regenerate_exam_attempt_recommendations(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $studentUser = User::factory()->student()->create([
            'institution_id' => $institution->id,
        ]);
        Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        $exam = \App\Models\Exams\Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);

        $attempt = \App\Models\Exams\ExamAttempt::factory()->submitted()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
            'student_user_id' => $studentUser->id,
        ]);

        $this->actingAs($studentUser, 'sanctum');

        $res = $this->postJson("/api/exam-attempts/{$attempt->id}/recommendations/regenerate");

        $res->assertSuccessful();
    }

    /**
     * El cupo de regeneraciones se contaba con `ceil($totalFilas / 4)` sobre
     * TODAS las recomendaciones del par (examen, materia), con dos errores:
     *
     * 1. `generateFromAttempt` guarda 1 o 2 filas según el porcentaje, no 4, así
     *    que la división no contaba generaciones reales.
     * 2. Sin corte temporal, un **segundo intento** del mismo examen nacía con el
     *    cupo del primero ya consumido.
     *
     * Ahora se cuentan instantes `generated_at` distintos desde la entrega de
     * este intento, que es lo que sí equivale a una generación.
     */
    public function test_a_new_attempt_gets_its_own_regeneration_budget(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        $subject = Subject::factory()->create(['institution_id' => $institution->id]);
        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'subject_id' => $subject->id,
            'created_by_teacher_id' => $teacher->id,
            'max_attempts' => 3,
        ]);

        // Primer intento, con el cupo agotado: 4 generaciones en 4 instantes.
        $primero = \App\Models\Exams\ExamAttempt::factory()->submitted()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
            'student_user_id' => $studentUser->id,
            'submitted_at' => now()->subHours(2),
        ]);

        foreach (range(1, 4) as $i) {
            foreach (range(1, 4) as $j) {
                AiRecommendation::factory()->create([
                    'institution_id' => $institution->id,
                    'student_user_id' => $studentUser->id,
                    'subject_id' => $subject->id,
                    'exam_id' => $exam->id,
                    'generated_at' => now()->subHours(2)->addMinutes($i),
                ]);
            }
        }

        $this->actingAs($studentUser, 'sanctum');

        $this->postJson("/api/exam-attempts/{$primero->id}/recommendations/regenerate")
            ->assertStatus(429);

        // Segundo intento, entregado después: cupo limpio pese a las 16 filas
        // que ya existen para el mismo examen y materia.
        $segundo = \App\Models\Exams\ExamAttempt::factory()->submitted()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
            'student_user_id' => $studentUser->id,
            'attempt_number' => 2,
            'submitted_at' => now(),
        ]);

        $this->postJson("/api/exam-attempts/{$segundo->id}/recommendations/regenerate")
            ->assertSuccessful();
    }

    /**
     * El recurso sugerido era `orderBy('created_at','desc')->first()`: el último
     * subido al centro, idéntico para todo el mundo. Un alumno de 2.º recibía el
     * material de 6.º si era el más reciente, mientras la Figura 10 del informe
     * promete «recursos personalizados».
     *
     * Ahora se acota por el rango de grado del recurso y se prefiere dificultad
     * `basic`, que es lo que corresponde en esta rama (desempeño bajo).
     */
    public function test_the_suggested_resource_matches_the_students_grade(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
            'grade' => 2,
        ]);

        $subject = Subject::factory()->create(['institution_id' => $institution->id]);
        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'subject_id' => $subject->id,
        ]);

        // El más reciente, pero de segundo ciclo: no le sirve a un alumno de 2.º.
        \App\Models\Academic\StudyResource::factory()->create([
            'institution_id' => $institution->id,
            'title' => 'Fracciones para sexto',
            'grade_min' => 5,
            'grade_max' => 6,
            'created_at' => now(),
        ]);

        $adecuado = \App\Models\Academic\StudyResource::factory()->create([
            'institution_id' => $institution->id,
            'title' => 'Sumas para segundo',
            'grade_min' => 1,
            'grade_max' => 3,
            'difficulty' => 'basic',
            'created_at' => now()->subWeek(),
        ]);

        // Desempeño bajo: es la rama que sugiere recurso de refuerzo.
        $attempt = \App\Models\Exams\ExamAttempt::factory()->submitted()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
            'student_user_id' => $studentUser->id,
            'score' => 2,
            'max_score' => 10,
        ]);

        // El servicio se invoca fuera de una petición HTTP, así que el tenant lo
        // fija aquí lo que en producción pone `SetTenantFromAuth`.
        app()->instance('tenant_id', $institution->id);

        $creadas = app(\App\Services\AI\AiRecommendationService::class)
            ->generateFromAttempt($attempt);

        $recurso = collect($creadas)
            ->firstWhere(fn ($r) => $r->recommendation_type->value === 'resource');

        $this->assertNotNull($recurso, 'La rama de bajo desempeño debe sugerir un recurso');
        $this->assertSame($adecuado->title, $recurso->resource['title']);
    }
}
