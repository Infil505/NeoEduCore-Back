<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Academic\Subject;
use App\Models\Academic\Group;
use App\Models\Academic\StudyResource;
use App\Models\Academic\CalendarEvent;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;
use App\Models\AI\AiRecommendation;
use Illuminate\Support\Str;
use App\Enums\UserType;
use App\Enums\UserStatus;
use App\Enums\ExamStatus;
use App\Enums\StudentStatus;
use App\Enums\QuestionType;
use Illuminate\Support\Facades\DB;

class CoreTablesSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Crear institución
        $institution = Institution::create([
            'id' => Str::uuid(),
            'code' => 'NEO001',
            'name' => 'Institución Educativa NeoEduCore',
            'address' => 'Calle Principal 456',
            'phone' => '310-1234567',
            'email' => 'info@neoeducore.edu.co',
            'is_active' => true,
        ]);

        // 2. Crear usuarios (admin, profesores, estudiantes)
        $admin = User::create([
            'id' => Str::uuid(),
            'institution_id' => $institution->id,
            'email' => 'admin@neoeducore.edu.co',
            'password_hash' => bcrypt('password123'),
            'full_name' => 'Administrador Sistema',
            'user_type' => UserType::Admin,
            'status' => UserStatus::Active,
        ]);

        $teacher1 = User::create([
            'id' => Str::uuid(),
            'institution_id' => $institution->id,
            'email' => 'profesor1@neoeducore.edu.co',
            'password_hash' => bcrypt('password123'),
            'full_name' => 'Prof. Juan García',
            'user_type' => UserType::Teacher,
            'status' => UserStatus::Active,
        ]);

        $teacher2 = User::create([
            'id' => Str::uuid(),
            'institution_id' => $institution->id,
            'email' => 'profesor2@neoeducore.edu.co',
            'password_hash' => bcrypt('password123'),
            'full_name' => 'Prof. María López',
            'user_type' => UserType::Teacher,
            'status' => UserStatus::Active,
        ]);

        // Crear estudiantes
        $studentUsers = [];
        for ($i = 1; $i <= 5; $i++) {
            $studentUsers[] = User::create([
                'id' => Str::uuid(),
                'institution_id' => $institution->id,
                'email' => "estudiante{$i}@neoeducore.edu.co",
                'password_hash' => bcrypt('password123'),
                'full_name' => "Estudiante Test {$i}",
                'user_type' => UserType::Student,
                'status' => UserStatus::Active,
            ]);
        }

        // 3. Crear asignaturas
        $math = Subject::create([
            'id' => Str::uuid(),
            'institution_id' => $institution->id,
            'name' => 'Matemáticas',
        ]);

        $spanish = Subject::create([
            'id' => Str::uuid(),
            'institution_id' => $institution->id,
            'name' => 'Español',
        ]);

        $english = Subject::create([
            'id' => Str::uuid(),
            'institution_id' => $institution->id,
            'name' => 'Inglés',
        ]);

        // 4. Crear grupos
        $group10a = Group::create([
            'id' => Str::uuid(),
            'institution_id' => $institution->id,
            'name' => '10-A',
            'grade' => 10,
            'section' => 'A',
            'year' => 2026,
            'group_code' => '10A2026',
            'student_count' => count($studentUsers),
        ]);

        // 5. Matricular estudiantes en grupos
        $adecuacionTypes = [\App\Enums\AdecuacionType::Acceso, \App\Enums\AdecuacionType::Contenido, null, null, \App\Enums\AdecuacionType::Evaluacion];
        
        foreach ($studentUsers as $index => $studentUser) {
            Student::create([
                'user_id' => $studentUser->id,
                'institution_id' => $institution->id,
                'student_code' => 'EST-' . substr($studentUser->id, 0, 8),
                'grade' => 10,
                'section' => 'A',
                'year' => 2026,
                'group_code' => '10A2026',
                'status' => StudentStatus::Active,
                'enrolled_at' => now()->subDays(rand(30, 180)),
                'last_activity_at' => now()->subHours(rand(1, 48)),
                'exams_completed_count' => rand(0, 5),
                'overall_average' => rand(50, 100) + rand(0, 99) / 100,
                'birth_date' => now()->subYears(15)->subDays(rand(0, 365)),
                'parent_name' => 'Acudiente ' . $studentUser->full_name,
                'parent_email' => 'parent' . ($index + 1) . '@mail.com',
                'adecuacion_type' => $adecuacionTypes[$index] ?? null,
            ]);

            // Insert into group_students pivot table
            DB::table('group_students')->insert([
                'id' => Str::uuid(),
                'institution_id' => $institution->id,
                'group_id' => $group10a->id,
                'student_user_id' => $studentUser->id,
                'joined_at' => now()->subDays(rand(30, 180)),
            ]);
        }

        // 6. Crear examen
        $exam1 = Exam::create([
            'id' => Str::uuid(),
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher1->id,
            'title' => 'Parcial 1 - Matemáticas',
            'subject_id' => $math->id,
            'grade' => 10,
            'instructions' => 'Responder todas las preguntas. Duración: 60 minutos.',
            'duration_minutes' => 60,
            'status' => ExamStatus::Active,
            'max_attempts' => 2,
            'show_results_immediately' => true,
        ]);

        // Asociar grupo al examen
        DB::table('exam_targets')->insert([
            'id' => Str::uuid(),
            'institution_id' => $institution->id,
            'exam_id' => $exam1->id,
            'group_id' => $group10a->id,
        ]);

        // 7. Crear preguntas
        $question1 = Question::create([
            'id' => Str::uuid(),
            'institution_id' => $institution->id,
            'exam_id' => $exam1->id,
            'question_text' => '¿Cuál es el resultado de 2 + 2?',
            'question_type' => QuestionType::MultipleChoice,
            'points' => 1,
            'order_index' => 1,
        ]);

        // Crear opciones
        $question1->options()->create([
            'institution_id' => $institution->id,
            'option_index' => 1,
            'option_text' => '3',
            'is_correct' => false,
        ]);
        $question1->options()->create([
            'institution_id' => $institution->id,
            'option_index' => 2,
            'option_text' => '4',
            'is_correct' => true,
        ]);
        $question1->options()->create([
            'institution_id' => $institution->id,
            'option_index' => 3,
            'option_text' => '5',
            'is_correct' => false,
        ]);

        // 8. Crear intentos de examen
        $attempt = ExamAttempt::create([
            'id' => Str::uuid(),
            'institution_id' => $institution->id,
            'exam_id' => $exam1->id,
            'student_user_id' => $studentUsers[0]->id,
            'attempt_number' => 1,
            'started_at' => now()->subHours(2),
            'submitted_at' => now()->subHours(1),
            'score' => 1.0,
            'max_score' => 1.0,
            'grade_status' => 'graded',
        ]);

        // 9. Crear progreso de estudiante
        StudentProgress::create([
            'institution_id' => $institution->id,
            'student_user_id' => $studentUsers[0]->id,
            'subject_id' => $math->id,
            'mastery_percentage' => 85.50,
        ]);

        // 10. Crear recursos de estudio
        StudyResource::create([
            'institution_id' => $institution->id,
            'title' => 'Introducción a Algebra',
            'description' => 'Video tutorial sobre conceptos básicos de álgebra',
            'resource_type' => 'video',
            'url' => 'https://youtube.com/watch?v=example',
            'estimated_duration' => 30,
            'difficulty' => 'basic',
            'grade_min' => 9,
            'grade_max' => 11,
            'language' => 'es',
            'created_by' => $teacher1->id,
        ]);

        StudyResource::create([
            'institution_id' => $institution->id,
            'title' => 'Ecuaciones Lineales Avanzadas',
            'description' => 'Guía completa sobre ecuaciones lineales',
            'resource_type' => 'pdf',
            'url' => 'https://example.com/guide.pdf',
            'estimated_duration' => 45,
            'difficulty' => 'intermediate',
            'grade_min' => 10,
            'grade_max' => 12,
            'language' => 'es',
            'created_by' => $teacher1->id,
        ]);

        StudyResource::create([
            'institution_id' => $institution->id,
            'title' => 'Ejercicios de Práctica - Álgebra',
            'description' => 'Colección de ejercicios prácticos resueltos',
            'resource_type' => 'link',
            'url' => 'https://example.com/exercises',
            'estimated_duration' => 60,
            'difficulty' => 'advanced',
            'grade_min' => 10,
            'grade_max' => 12,
            'language' => 'es',
            'created_by' => $teacher2->id,
        ]);

        // 11. Crear eventos de calendario
        CalendarEvent::create([
            'institution_id' => $institution->id,
            'title' => 'Parcial 1 - Matemáticas',
            'description' => 'Examen parcial de matemáticas para grado 10',
            'start_at' => now()->addDays(7)->setHour(8)->setMinute(0),
            'end_at' => now()->addDays(7)->setHour(10)->setMinute(0),
            'event_type' => 'exam',
            'exam_id' => $exam1->id,
            'group_id' => $group10a->id,
            'created_by' => $teacher1->id,
        ]);

        CalendarEvent::create([
            'institution_id' => $institution->id,
            'title' => 'Sesión de Refuerzo - Álgebra',
            'description' => 'Clase de refuerzo para estudiantes que lo necesitan',
            'start_at' => now()->addDays(5)->setHour(3)->setMinute(0)->addHours(3),
            'end_at' => now()->addDays(5)->setHour(4)->setMinute(30)->addHours(3),
            'event_type' => 'activity',
            'group_id' => $group10a->id,
            'created_by' => $teacher1->id,
        ]);

        CalendarEvent::create([
            'institution_id' => $institution->id,
            'title' => 'Recordatorio - Tarea Matemáticas',
            'description' => 'Entrega de tarea complementaria sobre ecuaciones',
            'start_at' => now()->addDays(3)->setHour(5)->setMinute(0)->addHours(4),
            'event_type' => 'reminder',
            'group_id' => $group10a->id,
            'created_by' => $teacher1->id,
        ]);

        // 12. Crear recomendaciones de IA
        AiRecommendation::create([
            'institution_id' => $institution->id,
            'student_user_id' => $studentUsers[0]->id,
            'subject_id' => $math->id,
            'recommendation_text' => 'Basado en tu desempeño (85%), te va muy bien en matemáticas. Continúa practicando con ejercicios avanzados.',
            'generated_at' => now(),
            'recommendation_type' => 'study_plan',
        ]);

        AiRecommendation::create([
            'institution_id' => $institution->id,
            'student_user_id' => $studentUsers[1]->id,
            'subject_id' => $math->id,
            'recommendation_text' => 'Te recomendamos revisar el recurso "Introducción a Álgebra" para reforzar tus conceptos básicos.',
            'generated_at' => now(),
            'recommendation_type' => 'support_resource',
        ]);

        AiRecommendation::create([
            'institution_id' => $institution->id,
            'student_user_id' => $studentUsers[2]->id,
            'subject_id' => $spanish->id,
            'recommendation_text' => 'Tu progreso en español es bueno. Considera explorar ejercicios de nivel intermedio.',
            'generated_at' => now(),
            'recommendation_type' => 'study_plan',
        ]);

        // 13. Crear más registros de progreso
        StudentProgress::create([
            'institution_id' => $institution->id,
            'student_user_id' => $studentUsers[0]->id,
            'subject_id' => $spanish->id,
            'mastery_percentage' => 72.00,
        ]);

        StudentProgress::create([
            'institution_id' => $institution->id,
            'student_user_id' => $studentUsers[0]->id,
            'subject_id' => $english->id,
            'mastery_percentage' => 68.50,
        ]);

        StudentProgress::create([
            'institution_id' => $institution->id,
            'student_user_id' => $studentUsers[1]->id,
            'subject_id' => $math->id,
            'mastery_percentage' => 55.00,
        ]);

        StudentProgress::create([
            'institution_id' => $institution->id,
            'student_user_id' => $studentUsers[2]->id,
            'subject_id' => $spanish->id,
            'mastery_percentage' => 78.75,
        ]);
    }
}
