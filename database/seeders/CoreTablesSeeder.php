<?php

namespace Database\Seeders;

use App\Enums\AdecuacionType;
use App\Enums\ExamStatus;
use App\Enums\QuestionType;
use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Academic\CalendarEvent;
use App\Models\Academic\Group;
use App\Models\Academic\StudyResource;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\AI\AiRecommendation;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Exams\Question;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Datos de demostración **pequeños** (es lo que siembra `php artisan db:seed`).
 *
 * Reflejan las reglas del sistema tal como son hoy, para que ninguna pantalla
 * salga vacía y se pueda ver cada regla funcionando:
 *
 *  - **4.º de primaria** (los grados salen de `config/academic.php`, 1.º–6.º).
 *  - **Dos aulas**, 4-A y 4-B. Quien da clase lo hace por una **asignación del
 *    administrador** (docente → aula → materia); sin ella no se ve a nadie.
 *  - **El docente solo ve lo suyo.** Hay un examen de cada docente en la misma
 *    aula, para comprobar que no se ven entre sí.
 *  - **Recursos y avisos** los crea un docente y los envía a las aulas que elige
 *    de las que tiene asignadas; el estudiante ve solo lo de su aula.
 *  - La duración de los exámenes respeta `max_exam_duration` del centro.
 *
 * Es **idempotente**: si el centro `NEO001` ya existe, o ya hay una cuenta con el
 * correo del administrador, no hace nada (lo que hay no se toca). Para volver a
 * sembrar hay que borrar el centro **con sus cuentas** (`DELETE /institutions/{id}`
 * lo hace): `users.institution_id` es `ON DELETE SET NULL`, así que borrar solo la
 * fila de la institución deja cuentas huérfanas que seguirían ocupando los correos.
 * No crea superadministrador: ese solo se crea por consola
 * (`php artisan superadmin:create`).
 *
 * Cuentas (todas activas, contraseña `password123`):
 *   admin@neoeducore.edu.co        administrador
 *   profesor1@neoeducore.edu.co    Matemáticas en 4-A y 4-B
 *   profesor2@neoeducore.edu.co    Español e Inglés en 4-A
 *   estudiante1..4@…               alumnado de 4-A
 *   estudiante5@…                  alumnado de 4-B
 */
class CoreTablesSeeder extends Seeder
{
    public const CODIGO_INSTITUCION = 'NEO001';
    public const CLAVE = 'password123';

    public function run(): void
    {
        $yaHayCentro = Institution::where('code', self::CODIGO_INSTITUCION)->exists();
        $yaHayCuentas = User::where('email', 'like', '%@neoeducore.edu.co')->exists();

        if ($yaHayCentro || $yaHayCuentas) {
            $this->command?->warn(
                'Ya existen el centro ' . self::CODIGO_INSTITUCION . ' o sus cuentas (@neoeducore.edu.co): no se siembra nada. '
                . 'Para volver a sembrar, borra el centro con sus cuentas.'
            );

            return;
        }

        DB::transaction(function () {
            $this->sembrar();
        });
    }

    private function sembrar(): void
    {
        // 1. Institución
        $institution = Institution::create([
            'id' => Str::uuid(),
            'code' => self::CODIGO_INSTITUCION,
            'name' => 'Institución Educativa NeoEduCore',
            'address' => 'Calle Principal 456',
            'phone' => '310-1234567',
            'email' => 'info@neoeducore.edu.co',
            'is_active' => true,
        ]);

        // El tenant scope se alimenta del contenedor: en consola hay que fijarlo.
        app()->instance('tenant_id', $institution->id);

        // 2. Cuentas
        $admin    = $this->usuario($institution, 'admin', 'Administrador Sistema', UserType::Admin);
        $teacher1 = $this->usuario($institution, 'profesor1', 'Prof. Juan García', UserType::Teacher);
        $teacher2 = $this->usuario($institution, 'profesor2', 'Prof. María López', UserType::Teacher);

        $studentUsers = [];
        for ($i = 1; $i <= 5; $i++) {
            $studentUsers[] = $this->usuario($institution, "estudiante{$i}", "Estudiante Test {$i}", UserType::Student);
        }

        // 3. Materias
        $math    = Subject::create(['id' => Str::uuid(), 'institution_id' => $institution->id, 'name' => 'Matemáticas']);
        $spanish = Subject::create(['id' => Str::uuid(), 'institution_id' => $institution->id, 'name' => 'Español']);
        $english = Subject::create(['id' => Str::uuid(), 'institution_id' => $institution->id, 'name' => 'Inglés']);

        // 4. Aulas (4.º de primaria)
        $aulaA = Group::create([
            'id' => Str::uuid(), 'institution_id' => $institution->id, 'name' => '4-A', 'grade' => 4,
            'section' => 'A', 'year' => 2026, 'group_code' => '4A2026', 'student_count' => 4,
        ]);
        $aulaB = Group::create([
            'id' => Str::uuid(), 'institution_id' => $institution->id, 'name' => '4-B', 'grade' => 4,
            'section' => 'B', 'year' => 2026, 'group_code' => '4B2026', 'student_count' => 1,
        ]);

        // 5. Asignación docente → aula → materia: lo hace el administrador y de ella
        //    sale TODO lo que un docente ve (alumnado, exámenes que puede dirigir,
        //    aulas donde puede enviar recursos y avisos).
        $asignaciones = [
            [$teacher1, $aulaA, $math], [$teacher1, $aulaB, $math],
            [$teacher2, $aulaA, $spanish], [$teacher2, $aulaA, $english],
        ];
        foreach ($asignaciones as [$docente, $aula, $materia]) {
            DB::table('teacher_assignments')->insert([
                'id' => Str::uuid(), 'institution_id' => $institution->id,
                'teacher_user_id' => $docente->id, 'group_id' => $aula->id, 'subject_id' => $materia->id,
                'assigned_at' => now()->subDays(60), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // 6. Alumnado: perfil, matrícula en el aula y en las materias que ahí se dan.
        $adecuaciones = [AdecuacionType::Acceso, AdecuacionType::Contenido, null, null, AdecuacionType::Evaluacion];
        $materiasDe   = [
            $aulaA->id => [$math, $spanish, $english],
            $aulaB->id => [$math],
        ];

        foreach ($studentUsers as $i => $user) {
            $aula = $i < 4 ? $aulaA : $aulaB;

            Student::create([
                'user_id' => $user->id,
                'institution_id' => $institution->id,
                // Secuencial, no un trozo del UUID: los UUID son ordenados por tiempo y
                // los de dos alumnos creados en el mismo instante empiezan igual, así
                // que el código chocaba con la restricción de unicidad.
                'student_code' => sprintf('EST-%04d', $i + 1),
                'grade' => $aula->grade,
                'section' => $aula->section,
                'year' => 2026,
                'group_code' => $aula->group_code,
                'status' => StudentStatus::Active,
                'enrolled_at' => now()->subDays(120),
                'last_activity_at' => now()->subHours(rand(1, 48)),
                'exams_completed_count' => $i === 0 ? 1 : 0,
                'overall_average' => $i === 0 ? 33.33 : null,
                'birth_date' => now()->subYears(10)->subDays($i * 17)->toDateString(),
                'parent_name' => 'Acudiente ' . $user->full_name,
                'parent_email' => 'parent' . ($i + 1) . '@mail.com',
                'adecuacion_type' => $adecuaciones[$i],
            ]);

            DB::table('group_students')->insert([
                'id' => Str::uuid(), 'institution_id' => $institution->id,
                'group_id' => $aula->id, 'student_user_id' => $user->id,
                'joined_at' => now()->subDays(120),
            ]);

            foreach ($materiasDe[$aula->id] as $materia) {
                DB::table('student_subjects')->insert([
                    'id' => Str::uuid(), 'institution_id' => $institution->id,
                    'student_user_id' => $user->id, 'subject_id' => $materia->id,
                    'enrolled_at' => now()->subDays(120), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        // 7. Exámenes. Cada uno lo crea su docente, en una materia y un aula que
        //    tiene asignadas, y dura menos que `max_exam_duration` (180 min por defecto).
        $examenMate = Exam::create([
            'id' => Str::uuid(), 'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher1->id,
            'title' => 'Parcial 1 - Matemáticas', 'subject_id' => $math->id, 'grade' => 4,
            'instructions' => 'Responde todas las preguntas. Duración: 60 minutos.',
            'duration_minutes' => 60, 'status' => ExamStatus::Active,
            'max_attempts' => 2, 'show_results_immediately' => true,
        ]);
        $examenMate->syncGroups([$aulaA->id]);

        // Del OTRO docente, en la misma aula: sirve para comprobar que entre docentes
        // no se ve lo que tiene cada uno (el de Matemáticas no ve este, y al revés).
        $examenEspanol = Exam::create([
            'id' => Str::uuid(), 'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher2->id,
            'title' => 'Borrador - Comprensión lectora', 'subject_id' => $spanish->id, 'grade' => 4,
            'instructions' => 'Lee el texto y responde.',
            'duration_minutes' => 30, 'status' => ExamStatus::Draft,
            'max_attempts' => 1, 'show_results_immediately' => true,
        ]);
        $examenEspanol->syncGroups([$aulaA->id]);

        // 8. Preguntas del examen de Matemáticas (opción múltiple: exactamente 4 opciones)
        $preguntaOpciones = Question::create([
            'id' => Str::uuid(), 'institution_id' => $institution->id, 'exam_id' => $examenMate->id,
            'question_text' => '¿Cuál es el resultado de 2 + 2?',
            'question_type' => QuestionType::MultipleChoice, 'points' => 1, 'order_index' => 1,
            'topic' => 'Suma', 'difficulty' => 'basic',
        ]);
        foreach ([[1, '3', false], [2, '4', true], [3, '5', false], [4, '22', false]] as [$n, $texto, $correcta]) {
            $preguntaOpciones->options()->create([
                'institution_id' => $institution->id, 'option_index' => $n,
                'option_text' => $texto, 'is_correct' => $correcta,
            ]);
        }

        $preguntaCorta = Question::create([
            'id' => Str::uuid(), 'institution_id' => $institution->id, 'exam_id' => $examenMate->id,
            'question_text' => '¿Cuánto es la mitad de 10?',
            'question_type' => QuestionType::ShortAnswer, 'points' => 2, 'order_index' => 2,
            'correct_answer_text' => '5', 'topic' => 'División', 'difficulty' => 'basic',
        ]);

        // 9. Un intento entregado de la estudiante 1 (acertó la 1.ª, falló la 2.ª: 1 de 3)
        $intento = ExamAttempt::create([
            'id' => Str::uuid(), 'institution_id' => $institution->id, 'exam_id' => $examenMate->id,
            'student_user_id' => $studentUsers[0]->id, 'attempt_number' => 1,
            'started_at' => now()->subHours(2), 'submitted_at' => now()->subHour(),
            'score' => 1.0, 'max_score' => 3.0, 'grade_status' => 'graded',
        ]);
        foreach ([[$preguntaOpciones, true, 1, null], [$preguntaCorta, false, 0, '6']] as [$pregunta, $acerto, $puntos, $texto]) {
            DB::table('student_answers')->insert([
                'id' => Str::uuid(), 'institution_id' => $institution->id, 'attempt_id' => $intento->id,
                'question_id' => $pregunta->id, 'answer_text' => $texto, 'is_correct' => $acerto,
                'points_awarded' => $puntos, 'answered_at' => now()->subHour(), 'review_status' => 'auto_graded',
                'created_at' => now()->subHour(), 'updated_at' => now()->subHour(),
            ]);
        }

        // 10. Progreso (solo en materias que el alumno cursa)
        foreach ([
            [0, $math, 33.33], [0, $spanish, 72.00], [0, $english, 68.50],
            [1, $math, 55.00], [2, $spanish, 78.75],
        ] as [$i, $materia, $dominio]) {
            StudentProgress::create([
                'institution_id' => $institution->id, 'student_user_id' => $studentUsers[$i]->id,
                'subject_id' => $materia->id, 'mastery_percentage' => $dominio,
            ]);
        }

        // 11. Recursos de estudio. Los crea el docente que imparte la materia y los
        //     ENVÍA a aulas suyas: un recurso sin aula no lo vería ningún estudiante.
        $this->recurso($institution, $teacher1, $math, [$aulaA, $aulaB], 'Introducción a Álgebra',
            'Video tutorial sobre conceptos básicos', 'video', 'https://es.khanacademy.org/math/algebra-basics', 30, 'basic');
        $this->recurso($institution, $teacher1, $math, [$aulaA], 'Ejercicios de práctica - sumas y restas',
            'Colección de ejercicios resueltos', 'exercise', 'https://www.geogebra.org/m/ejercicios-sumas', 45, 'intermediate');
        $this->recurso($institution, $teacher2, $spanish, [$aulaA], 'Comprensión de lectura: cuentos cortos',
            'Lecturas breves con preguntas', 'article', 'https://es.wikipedia.org/wiki/Comprension_lectora', 20, 'basic');

        // 12. Avisos del calendario: siempre con aula, y de un docente asignado a ella.
        $this->evento($institution, $teacher1, $aulaA, 'Parcial 1 - Matemáticas', 'exam',
            now()->addDays(7)->setTime(8, 0), now()->addDays(7)->setTime(10, 0), $examenMate->id);
        $this->evento($institution, $teacher1, $aulaA, 'Sesión de refuerzo - Álgebra', 'activity',
            now()->addDays(5)->setTime(14, 0), now()->addDays(5)->setTime(15, 30));
        $this->evento($institution, $teacher1, $aulaB, 'Recordatorio - Tarea de Matemáticas', 'reminder',
            now()->addDays(3)->setTime(9, 0), now()->addDays(3)->setTime(9, 30));
        $this->evento($institution, $teacher2, $aulaA, 'Reunión de padres y madres', 'meeting',
            now()->addDays(10)->setTime(17, 0), now()->addDays(10)->setTime(18, 0));

        // 13. Recomendaciones de IA (tipos válidos del enum; materias que el alumno cursa)
        $recomendaciones = [
            [0, $math, 'action', 'Repasa las divisiones sencillas: la mitad de un número es repartirlo en dos partes iguales.'],
            [1, $math, 'resource', 'Te recomendamos revisar el recurso "Introducción a Álgebra" para reforzar lo básico.'],
            [2, $spanish, 'action', 'Tu progreso en español es bueno. Prueba lecturas un poco más largas.'],
        ];
        foreach ($recomendaciones as [$i, $materia, $tipo, $texto]) {
            AiRecommendation::create([
                'institution_id' => $institution->id, 'student_user_id' => $studentUsers[$i]->id,
                'subject_id' => $materia->id, 'recommendation_text' => $texto,
                'generated_at' => now(), 'recommendation_type' => $tipo,
            ]);
        }

        app()->forgetInstance('tenant_id');

        $this->command?->info('Centro NEO001 sembrado: contraseña de todas las cuentas = ' . self::CLAVE);
    }

    private function usuario(Institution $institution, string $local, string $nombre, UserType $rol): User
    {
        return User::create([
            'id' => Str::uuid(),
            'institution_id' => $institution->id,
            'email' => "{$local}@neoeducore.edu.co",
            'password_hash' => bcrypt(self::CLAVE),
            'full_name' => $nombre,
            'user_type' => $rol,
            'status' => UserStatus::Active,
        ]);
    }

    /** @param  array<int,Group>  $aulas */
    private function recurso(Institution $institution, User $docente, Subject $materia, array $aulas, string $titulo,
                             string $descripcion, string $tipo, string $url, int $minutos, string $dificultad): void
    {
        $recurso = StudyResource::create([
            'institution_id' => $institution->id, 'subject_id' => $materia->id,
            'title' => $titulo, 'description' => $descripcion, 'resource_type' => $tipo, 'url' => $url,
            'estimated_duration' => $minutos, 'difficulty' => $dificultad,
            'grade_min' => 3, 'grade_max' => 6, 'language' => 'es', 'created_by' => $docente->id,
        ]);

        $recurso->syncGroups(array_map(fn (Group $a) => $a->id, $aulas));
    }

    private function evento(Institution $institution, User $docente, Group $aula, string $titulo, string $tipo,
                            $inicio, $fin, ?string $examenId = null): void
    {
        CalendarEvent::create([
            'institution_id' => $institution->id, 'title' => $titulo, 'description' => null,
            'start_at' => $inicio, 'end_at' => $fin, 'event_type' => $tipo,
            'exam_id' => $examenId, 'group_id' => $aula->id, 'created_by' => $docente->id,
        ]);
    }
}
