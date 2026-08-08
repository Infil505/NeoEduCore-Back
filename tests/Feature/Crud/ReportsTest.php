<?php

namespace Tests\Feature\Crud;

use App\Models\Academic\Subject;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Admin\User;
use App\Models\Admin\Institution;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

class ReportsTest extends TestCase
{
    use ApiAuth;

    public function test_exam_results_report(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $student1 = User::factory()->student()->create([
            'institution_id' => $institution->id,
        ]);
        Student::factory()->create([
            'user_id' => $student1->id,
            'institution_id' => $institution->id,
        ]);

        $student2 = User::factory()->student()->create([
            'institution_id' => $institution->id,
        ]);
        Student::factory()->create([
            'user_id' => $student2->id,
            'institution_id' => $institution->id,
        ]);

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);

        ExamAttempt::factory()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
            'student_user_id' => $student1->id,
            'score' => 80,
            'max_score' => 100,
        ]);

        ExamAttempt::factory()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
            'student_user_id' => $student2->id,
            'score' => 70,
            'max_score' => 100,
        ]);

        $res = $this->getJson("/api/reports/exams/{$exam->id}/results");

        $res->assertOk();
    }

    public function test_exam_results_csv_export(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $student = User::factory()->student()->create([
            'institution_id' => $institution->id,
        ]);
        Student::factory()->create([
            'user_id' => $student->id,
            'institution_id' => $institution->id,
        ]);

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);

        ExamAttempt::factory()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
            'student_user_id' => $student->id,
        ]);

        $res = $this->getJson("/api/reports/exams/{$exam->id}/results.csv");

        $res->assertOk();
        // Verificar que es CSV
        $this->assertTrue(
            str_contains($res->headers->get('content-type'), 'text/csv') ||
            str_contains($res->headers->get('content-type'), 'application/csv')
        );
    }

    public function test_student_history_report(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $studentUser = User::factory()->student()->create([
            'institution_id' => $institution->id,
        ]);
        $student = Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        $this->darAccesoDocenteA($teacher, $studentUser->id, $institution->id);

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);

        ExamAttempt::factory()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
            'student_user_id' => $studentUser->id,
        ]);

        $res = $this->getJson("/api/reports/students/{$studentUser->id}/history");

        $res->assertOk();
    }

    public function test_student_history_csv_export(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $studentUser = $this->makeStudent($institution);
        $this->darAccesoDocenteA($teacher, $studentUser->id, $institution->id);

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);

        ExamAttempt::factory()->submitted()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
            'student_user_id' => $studentUser->id,
            'score' => 8,
            'max_score' => 10,
        ]);

        $res = $this->get("/api/reports/students/{$studentUser->id}/history.csv");

        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('content-type'));
        $this->assertStringContainsString('exam_title', $res->streamedContent());
    }

    /**
     * El resumen alimenta los tres gráficos del frontend, así que se comprueban
     * los recuentos exactos: un error de un tramo en el histograma no rompe
     * ninguna petición, solo dibuja mal.
     */
    public function test_exam_summary_returns_the_series_for_the_charts(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);

        $this->seedAttempts($institution, $exam, [95, 85, 72, 66, 40]);

        $res = $this->getJson("/api/reports/exams/{$exam->id}/summary");

        $res->assertOk();
        $data = $res->json('data');

        $this->assertEquals(65.0, $data['passing_percentage']);

        $this->assertSame(5, $data['totals']['attempts']);
        $this->assertEquals(71.6, $data['totals']['average']);
        $this->assertEquals(72.0, $data['totals']['median']);
        $this->assertEquals(95.0, $data['totals']['best']);
        $this->assertEquals(40.0, $data['totals']['worst']);
        $this->assertSame(4, $data['totals']['passed']);
        $this->assertSame(1, $data['totals']['failed']);
        $this->assertEquals(80.0, $data['totals']['pass_rate']);

        // Histograma (barras): un intento en cada tramo salvo 50-59, que va vacío
        $this->assertSame(
            ['0-49' => 1, '50-59' => 0, '60-69' => 1, '70-79' => 1, '80-89' => 1, '90-100' => 1],
            collect($data['score_distribution'])->pluck('count', 'range')->all()
        );

        // Niveles de desempeño (pastel), de mejor a peor
        $this->assertSame(
            ['advanced' => 1, 'satisfactory' => 1, 'in_progress' => 2, 'needs_support' => 1],
            collect($data['performance_levels'])->pluck('count', 'key')->all()
        );
        $this->assertSame(
            ['advanced', 'satisfactory', 'in_progress', 'needs_support'],
            collect($data['performance_levels'])->pluck('key')->all()
        );
    }

    public function test_exam_summary_honours_the_institution_passing_percentage(): void
    {
        $institution = Institution::factory()->create([
            'settings' => ['passing_percentage' => 80],
        ]);
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);

        $this->seedAttempts($institution, $exam, [95, 85, 72, 66, 40]);

        $data = $this->getJson("/api/reports/exams/{$exam->id}/summary")->assertOk()->json('data');

        $this->assertEquals(80.0, $data['passing_percentage']);
        $this->assertSame(2, $data['totals']['passed']);
        $this->assertEquals(40.0, $data['totals']['pass_rate']);

        // Con la nota mínima en 80, «En proceso» (>=80 y <80) queda vacío por
        // construcción y los tres reprobados caen en «Requiere apoyo».
        $this->assertSame(
            ['advanced' => 1, 'satisfactory' => 1, 'in_progress' => 0, 'needs_support' => 3],
            collect($data['performance_levels'])->pluck('count', 'key')->all()
        );
    }

    public function test_exam_summary_is_denied_for_another_teachers_exam(): void
    {
        $institution = Institution::factory()->create();
        $owner = User::factory()->teacher()->create(['institution_id' => $institution->id]);

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $owner->id,
        ]);

        $this->signInTeacher(['institution_id' => $institution->id]);

        $this->getJson("/api/reports/exams/{$exam->id}/summary")->assertForbidden();
    }

    public function test_student_summary_returns_trend_in_chronological_order(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $studentUser = $this->makeStudent($institution);
        $this->darAccesoDocenteA($teacher, $studentUser->id, $institution->id);

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);

        foreach ([[3, 50.0], [2, 70.0], [1, 90.0]] as $i => [$weeksAgo, $score]) {
            ExamAttempt::factory()->submitted()->create([
                'institution_id' => $institution->id,
                'exam_id' => $exam->id,
                'student_user_id' => $studentUser->id,
                'attempt_number' => $i + 1,
                'score' => $score,
                'max_score' => 100,
                'submitted_at' => now()->subWeeks($weeksAgo),
            ]);
        }

        $subject = Subject::factory()->create(['institution_id' => $institution->id]);
        StudentProgress::factory()->create([
            'institution_id' => $institution->id,
            'student_user_id' => $studentUser->id,
            'subject_id' => $subject->id,
            'mastery_percentage' => 77.5,
        ]);

        $data = $this->getJson("/api/reports/students/{$studentUser->id}/summary")->assertOk()->json('data');

        $this->assertSame(3, $data['totals']['attempts']);
        $this->assertEquals(70.0, $data['totals']['average']);
        $this->assertEquals(90.0, $data['totals']['best']);
        $this->assertEquals(90.0, $data['totals']['last']);
        $this->assertSame(2, $data['totals']['passed']);

        // El gráfico de líneas se lee de izquierda a derecha: del más antiguo al
        // más reciente, al revés que la tabla del historial.
        $this->assertEquals([50.0, 70.0, 90.0], collect($data['score_trend'])->pluck('percentage')->all());

        $this->assertSame($subject->name, $data['subject_mastery'][0]['subject']);
        $this->assertEquals(77.5, $data['subject_mastery'][0]['mastery_percentage']);
    }

    public function test_student_summary_trend_respects_the_points_parameter(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $studentUser = $this->makeStudent($institution);
        $this->darAccesoDocenteA($teacher, $studentUser->id, $institution->id);

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);

        foreach (range(1, 5) as $i) {
            ExamAttempt::factory()->submitted()->create([
                'institution_id' => $institution->id,
                'exam_id' => $exam->id,
                'student_user_id' => $studentUser->id,
                'attempt_number' => $i,
                'score' => 50 + $i,
                'max_score' => 100,
                'submitted_at' => now()->subDays(10 - $i),
            ]);
        }

        $data = $this->getJson("/api/reports/students/{$studentUser->id}/summary?points=2")
            ->assertOk()
            ->json('data');

        // Se piden los 2 últimos, no los 2 primeros
        $this->assertEquals([54.0, 55.0], collect($data['score_trend'])->pluck('percentage')->all());
        $this->assertSame(5, $data['totals']['attempts']);

        $this->getJson("/api/reports/students/{$studentUser->id}/summary?points=999")
            ->assertStatus(422);
    }

    private function makeStudent(Institution $institution): User
    {
        $user = User::factory()->student()->create([
            'institution_id' => $institution->id,
        ]);

        Student::factory()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);

        return $user;
    }

    /** @param  array<int,float>  $percentages  sobre 100 */
    private function seedAttempts(Institution $institution, Exam $exam, array $percentages): void
    {
        foreach ($percentages as $percentage) {
            ExamAttempt::factory()->submitted()->create([
                'institution_id' => $institution->id,
                'exam_id' => $exam->id,
                'student_user_id' => $this->makeStudent($institution)->id,
                'score' => $percentage,
                'max_score' => 100,
            ]);
        }
    }
}
