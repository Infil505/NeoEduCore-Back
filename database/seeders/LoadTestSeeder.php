<?php

namespace Database\Seeders;

use App\Models\Academic\Group;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Models\Exams\QuestionOption;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Siembra un escenario de PRUEBA DE CARGA.
 *
 * Reproduce el pico real del sistema: una institución con un examen activo de
 * 20 preguntas asignado a un grupo, y N estudiantes que lo entregan a la vez.
 *
 *   php artisan db:seed --class=LoadTestSeeder
 *   ESTUDIANTES=1000 php artisan db:seed --class=LoadTestSeeder
 *
 * ⚠️ Solo contra una base de datos DESECHABLE. Nunca contra producción.
 */
class LoadTestSeeder extends Seeder
{
    public const PASSWORD = 'Carga2026';
    public const CODIGO_INSTITUCION = 'LOAD001';

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('LoadTestSeeder no debe ejecutarse en producción.');
        }

        $nEstudiantes = (int) (env('ESTUDIANTES') ?: 200);
        $nPreguntas   = (int) (env('PREGUNTAS') ?: 20);

        $this->command->info("Sembrando {$nEstudiantes} estudiantes y un examen de {$nPreguntas} preguntas...");

        $institution = Institution::create([
            'code' => self::CODIGO_INSTITUCION,
            'name' => 'Institución de carga',
            'is_active' => true,
        ]);

        // El tenant scope se alimenta del contenedor; en consola hay que fijarlo.
        app()->instance('tenant_id', $institution->id);

        $hash = Hash::make(self::PASSWORD);

        $admin = User::create([
            'institution_id' => $institution->id, 'email' => 'admin@carga.test',
            'password_hash' => $hash, 'full_name' => 'Admin Carga',
            'user_type' => 'admin', 'status' => 'active',
        ]);
        $teacher = User::create([
            'institution_id' => $institution->id, 'email' => 'teacher@carga.test',
            'password_hash' => $hash, 'full_name' => 'Docente Carga',
            'user_type' => 'teacher', 'status' => 'active',
        ]);

        $subject = Subject::create([
            'institution_id' => $institution->id, 'name' => 'Matemática Carga',
        ]);
        $group = Group::create([
            'institution_id' => $institution->id, 'name' => 'Grupo Carga',
            'grade' => 7, 'section' => 'A', 'year' => (int) date('Y'),
            'group_code' => 'CARGA', 'student_count' => 0,
        ]);

        // max_attempts alto: cada VU de k6 entrega muchas veces durante la prueba.
        $exam = Exam::create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
            'title' => 'Examen de carga', 'subject_id' => $subject->id,
            'grade' => 7, 'duration_minutes' => 180, 'status' => 'active',
            'max_attempts' => 100000, 'available_from' => now()->subDay(),
            'available_until' => now()->addYear(),
        ]);
        DB::table('exam_targets')->insert([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id, 'group_id' => $group->id,
        ]);

        for ($i = 0; $i < $nPreguntas; $i++) {
            $q = Question::create([
                'institution_id' => $institution->id, 'exam_id' => $exam->id,
                'question_text' => "Pregunta de carga número {$i}: ¿es correcta esta afirmación?",
                'question_type' => 'true_false', 'points' => 1, 'order_index' => $i,
            ]);
            QuestionOption::insert([
                ['institution_id' => $institution->id, 'question_id' => $q->id,
                 'option_index' => 0, 'option_text' => 'Verdadero', 'is_correct' => true],
                ['institution_id' => $institution->id, 'question_id' => $q->id,
                 'option_index' => 1, 'option_text' => 'Falso', 'is_correct' => false],
            ]);
        }

        // Inserción por lotes: crear 1000 estudiantes de uno en uno tarda minutos.
        $ahora = now();
        $usuarios = $perfiles = $membresias = [];

        for ($i = 1; $i <= $nEstudiantes; $i++) {
            $id = (string) Str::orderedUuid();

            $usuarios[] = [
                'id' => $id, 'institution_id' => $institution->id,
                'email' => "alumno{$i}@carga.test", 'password_hash' => $hash,
                'full_name' => "Alumno Carga {$i}", 'user_type' => 'student',
                'status' => 'active', 'created_at' => $ahora, 'updated_at' => $ahora,
            ];
            $perfiles[] = [
                'user_id' => $id, 'institution_id' => $institution->id,
                'student_code' => sprintf('CARGA-%06d', $i),
                'grade' => 7, 'section' => 'A', 'year' => (int) date('Y'),
                'status' => 'active', 'enrolled_at' => $ahora,
                'exams_completed_count' => 0, 'group_code' => 'CARGA',
                'created_at' => $ahora, 'updated_at' => $ahora,
            ];
            $membresias[] = [
                'institution_id' => $institution->id, 'group_id' => $group->id,
                'student_user_id' => $id, 'joined_at' => $ahora, 'left_at' => null,
            ];
        }

        foreach (array_chunk($usuarios, 500) as $lote)  { DB::table('users')->insert($lote); }
        foreach (array_chunk($perfiles, 500) as $lote)  { DB::table('students')->insert($lote); }
        foreach (array_chunk($membresias, 500) as $lote) { DB::table('group_students')->insert($lote); }

        DB::table('groups')->where('id', $group->id)->update(['student_count' => $nEstudiantes]);

        $this->command->info("OK — institución {$institution->id}, examen {$exam->id}");
        $this->command->info("Credenciales: alumnoN@carga.test / " . self::PASSWORD);
    }
}
