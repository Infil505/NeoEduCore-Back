<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Alinea las claves foráneas con el modelo de datos del informe del TFG
 * (`schema de la base de datos.sql`).
 *
 * Se detectaron 33 divergencias, agrupadas en tres tipos:
 *
 * 1. **Destino equivocado (4).** `exam_attempts`, `student_progress`,
 *    `ai_recommendations` y `group_students` apuntaban a `users(id)` en vez de
 *    a `students(user_id)`. Con la FK laxa se podía crear, por ejemplo, un
 *    intento de examen a nombre de un docente. Verificado en la BD real.
 *
 * 2. **ON DELETE distinto (26).** Sobre todo el TFG pide CASCADE donde había
 *    NO ACTION. No era cosmético: con datos reales `DELETE /api/subjects/{id}`,
 *    `/groups/{id}`, `/exams/{id}` y `/users/{id}` **devolvían 500** porque la
 *    cascada se cortaba en la primera tabla hija. La documentación afirmaba que
 *    esos borrados cascadeaban; no era cierto. Los tests no lo detectaban
 *    porque todos borraban entidades vacías.
 *
 * 3. **FK ausentes (3).** `ai_recommendations.institution_id`,
 *    `question_options.institution_id` y `student_answers.institution_id`
 *    tenían la columna pero ninguna restricción: se podía escribir ahí un
 *    tenant inexistente.
 *
 * NO se tocan dos divergencias deliberadas, mejores que el diseño original y ya
 * documentadas (migración `fix_exam_cascade_constraints`):
 *   - `exams.created_by_teacher_id` → SET NULL (permite borrar un docente)
 *   - `exams.subject_id`            → CASCADE
 */
return new class extends Migration
{
    /** Destino nuevo de cada FK, según el modelo del TFG. */
    private function objetivo(): array
    {
        return [
            ['t' => 'ai_recommendations', 'c' => 'exam_id', 'n' => 'ai_recommendations_exam_id_foreign', 'rt' => 'exams', 'rc' => 'id', 'od' => 'SET NULL'],
            ['t' => 'ai_recommendations', 'c' => 'institution_id', 'n' => 'ai_recommendations_institution_id_foreign', 'rt' => 'institutions', 'rc' => 'id', 'od' => 'CASCADE'],
            ['t' => 'ai_recommendations', 'c' => 'student_user_id', 'n' => 'ai_recommendations_student_user_id_foreign', 'rt' => 'students', 'rc' => 'user_id', 'od' => 'CASCADE'],
            ['t' => 'ai_recommendations', 'c' => 'subject_id', 'n' => 'ai_recommendations_subject_id_foreign', 'rt' => 'subjects', 'rc' => 'id', 'od' => 'SET NULL'],
            ['t' => 'calendar_events', 'c' => 'created_by', 'n' => 'calendar_events_created_by_foreign', 'rt' => 'users', 'rc' => 'id', 'od' => 'SET NULL'],
            ['t' => 'calendar_events', 'c' => 'exam_id', 'n' => 'calendar_events_exam_id_foreign', 'rt' => 'exams', 'rc' => 'id', 'od' => 'SET NULL'],
            ['t' => 'calendar_events', 'c' => 'group_id', 'n' => 'calendar_events_group_id_foreign', 'rt' => 'groups', 'rc' => 'id', 'od' => 'SET NULL'],
            ['t' => 'calendar_events', 'c' => 'institution_id', 'n' => 'calendar_events_institution_id_foreign', 'rt' => 'institutions', 'rc' => 'id', 'od' => 'CASCADE'],
            ['t' => 'exam_attempts', 'c' => 'exam_id', 'n' => 'exam_attempts_exam_id_foreign', 'rt' => 'exams', 'rc' => 'id', 'od' => 'CASCADE'],
            ['t' => 'exam_attempts', 'c' => 'student_user_id', 'n' => 'exam_attempts_student_user_id_foreign', 'rt' => 'students', 'rc' => 'user_id', 'od' => 'CASCADE'],
            ['t' => 'exam_targets', 'c' => 'exam_id', 'n' => 'exam_targets_exam_id_foreign', 'rt' => 'exams', 'rc' => 'id', 'od' => 'CASCADE'],
            ['t' => 'exam_targets', 'c' => 'group_id', 'n' => 'exam_targets_group_id_foreign', 'rt' => 'groups', 'rc' => 'id', 'od' => 'CASCADE'],
            ['t' => 'exam_targets', 'c' => 'institution_id', 'n' => 'exam_targets_institution_id_foreign', 'rt' => 'institutions', 'rc' => 'id', 'od' => 'CASCADE'],
            ['t' => 'exams', 'c' => 'institution_id', 'n' => 'exams_institution_id_foreign', 'rt' => 'institutions', 'rc' => 'id', 'od' => 'CASCADE'],
            ['t' => 'group_students', 'c' => 'group_id', 'n' => 'group_students_group_id_foreign', 'rt' => 'groups', 'rc' => 'id', 'od' => 'CASCADE'],
            ['t' => 'group_students', 'c' => 'student_user_id', 'n' => 'group_students_student_user_id_foreign', 'rt' => 'students', 'rc' => 'user_id', 'od' => 'CASCADE'],
            ['t' => 'groups', 'c' => 'institution_id', 'n' => 'groups_institution_id_foreign', 'rt' => 'institutions', 'rc' => 'id', 'od' => 'CASCADE'],
            ['t' => 'question_options', 'c' => 'institution_id', 'n' => 'question_options_institution_id_foreign', 'rt' => 'institutions', 'rc' => 'id', 'od' => 'CASCADE'],
            ['t' => 'question_options', 'c' => 'question_id', 'n' => 'question_options_question_id_foreign', 'rt' => 'questions', 'rc' => 'id', 'od' => 'CASCADE'],
            ['t' => 'questions', 'c' => 'exam_id', 'n' => 'questions_exam_id_foreign', 'rt' => 'exams', 'rc' => 'id', 'od' => 'CASCADE'],
            ['t' => 'questions', 'c' => 'institution_id', 'n' => 'questions_institution_id_foreign', 'rt' => 'institutions', 'rc' => 'id', 'od' => 'CASCADE'],
            ['t' => 'student_answer_options', 'c' => 'institution_id', 'n' => 'student_answer_options_institution_id_foreign', 'rt' => 'institutions', 'rc' => 'id', 'od' => 'CASCADE'],
            ['t' => 'student_answer_options', 'c' => 'option_id', 'n' => 'student_answer_options_option_id_foreign', 'rt' => 'question_options', 'rc' => 'id', 'od' => 'CASCADE'],
            ['t' => 'student_answer_options', 'c' => 'student_answer_id', 'n' => 'student_answer_options_student_answer_id_foreign', 'rt' => 'student_answers', 'rc' => 'id', 'od' => 'CASCADE'],
            ['t' => 'student_answers', 'c' => 'attempt_id', 'n' => 'student_answers_attempt_id_foreign', 'rt' => 'exam_attempts', 'rc' => 'id', 'od' => 'CASCADE'],
            ['t' => 'student_answers', 'c' => 'institution_id', 'n' => 'student_answers_institution_id_foreign', 'rt' => 'institutions', 'rc' => 'id', 'od' => 'CASCADE'],
            ['t' => 'student_answers', 'c' => 'question_id', 'n' => 'student_answers_question_id_foreign', 'rt' => 'questions', 'rc' => 'id', 'od' => 'CASCADE'],
            ['t' => 'student_progress', 'c' => 'student_user_id', 'n' => 'student_progress_student_user_id_foreign', 'rt' => 'students', 'rc' => 'user_id', 'od' => 'CASCADE'],
            ['t' => 'student_progress', 'c' => 'subject_id', 'n' => 'student_progress_subject_id_foreign', 'rt' => 'subjects', 'rc' => 'id', 'od' => 'CASCADE'],
            ['t' => 'students', 'c' => 'user_id', 'n' => 'students_user_id_foreign', 'rt' => 'users', 'rc' => 'id', 'od' => 'CASCADE'],
            ['t' => 'study_resources', 'c' => 'institution_id', 'n' => 'study_resources_institution_id_foreign', 'rt' => 'institutions', 'rc' => 'id', 'od' => 'CASCADE'],
            ['t' => 'subjects', 'c' => 'institution_id', 'n' => 'subjects_institution_id_foreign', 'rt' => 'institutions', 'rc' => 'id', 'od' => 'CASCADE'],
            ['t' => 'users', 'c' => 'institution_id', 'n' => 'users_institution_id_foreign', 'rt' => 'institutions', 'rc' => 'id', 'od' => 'SET NULL'],
        ];
    }

    /** Estado previo, para poder revertir. */
    private function previo(): array
    {
        return [
            ['t' => 'ai_recommendations', 'c' => 'exam_id', 'n' => 'ai_recommendations_exam_id_foreign', 'rt' => 'exams', 'rc' => 'id', 'od' => null],
            ['t' => 'ai_recommendations', 'c' => 'student_user_id', 'n' => 'ai_recommendations_student_user_id_foreign', 'rt' => 'users', 'rc' => 'id', 'od' => null],
            ['t' => 'ai_recommendations', 'c' => 'subject_id', 'n' => 'ai_recommendations_subject_id_foreign', 'rt' => 'subjects', 'rc' => 'id', 'od' => null],
            ['t' => 'calendar_events', 'c' => 'created_by', 'n' => 'calendar_events_created_by_foreign', 'rt' => 'users', 'rc' => 'id', 'od' => null],
            ['t' => 'calendar_events', 'c' => 'exam_id', 'n' => 'calendar_events_exam_id_foreign', 'rt' => 'exams', 'rc' => 'id', 'od' => null],
            ['t' => 'calendar_events', 'c' => 'group_id', 'n' => 'calendar_events_group_id_foreign', 'rt' => 'groups', 'rc' => 'id', 'od' => null],
            ['t' => 'calendar_events', 'c' => 'institution_id', 'n' => 'calendar_events_institution_id_foreign', 'rt' => 'institutions', 'rc' => 'id', 'od' => null],
            ['t' => 'exam_attempts', 'c' => 'exam_id', 'n' => 'exam_attempts_exam_id_foreign', 'rt' => 'exams', 'rc' => 'id', 'od' => null],
            ['t' => 'exam_attempts', 'c' => 'student_user_id', 'n' => 'exam_attempts_student_user_id_foreign', 'rt' => 'users', 'rc' => 'id', 'od' => null],
            ['t' => 'exam_targets', 'c' => 'exam_id', 'n' => 'exam_targets_exam_id_foreign', 'rt' => 'exams', 'rc' => 'id', 'od' => null],
            ['t' => 'exam_targets', 'c' => 'group_id', 'n' => 'exam_targets_group_id_foreign', 'rt' => 'groups', 'rc' => 'id', 'od' => null],
            ['t' => 'exam_targets', 'c' => 'institution_id', 'n' => 'exam_targets_institution_id_foreign', 'rt' => 'institutions', 'rc' => 'id', 'od' => null],
            ['t' => 'exams', 'c' => 'institution_id', 'n' => 'exams_institution_id_foreign', 'rt' => 'institutions', 'rc' => 'id', 'od' => null],
            ['t' => 'group_students', 'c' => 'group_id', 'n' => 'group_students_group_id_foreign', 'rt' => 'groups', 'rc' => 'id', 'od' => null],
            ['t' => 'group_students', 'c' => 'student_user_id', 'n' => 'group_students_student_user_id_foreign', 'rt' => 'users', 'rc' => 'id', 'od' => null],
            ['t' => 'groups', 'c' => 'institution_id', 'n' => 'groups_institution_id_foreign', 'rt' => 'institutions', 'rc' => 'id', 'od' => null],
            ['t' => 'question_options', 'c' => 'question_id', 'n' => 'question_options_question_id_foreign', 'rt' => 'questions', 'rc' => 'id', 'od' => null],
            ['t' => 'questions', 'c' => 'exam_id', 'n' => 'questions_exam_id_foreign', 'rt' => 'exams', 'rc' => 'id', 'od' => null],
            ['t' => 'questions', 'c' => 'institution_id', 'n' => 'questions_institution_id_foreign', 'rt' => 'institutions', 'rc' => 'id', 'od' => null],
            ['t' => 'student_answer_options', 'c' => 'institution_id', 'n' => 'student_answer_options_institution_id_foreign', 'rt' => 'institutions', 'rc' => 'id', 'od' => null],
            ['t' => 'student_answer_options', 'c' => 'option_id', 'n' => 'student_answer_options_option_id_foreign', 'rt' => 'question_options', 'rc' => 'id', 'od' => null],
            ['t' => 'student_answer_options', 'c' => 'student_answer_id', 'n' => 'student_answer_options_student_answer_id_foreign', 'rt' => 'student_answers', 'rc' => 'id', 'od' => null],
            ['t' => 'student_answers', 'c' => 'attempt_id', 'n' => 'student_answers_attempt_id_foreign', 'rt' => 'exam_attempts', 'rc' => 'id', 'od' => null],
            ['t' => 'student_answers', 'c' => 'question_id', 'n' => 'student_answers_question_id_foreign', 'rt' => 'questions', 'rc' => 'id', 'od' => null],
            ['t' => 'student_progress', 'c' => 'student_user_id', 'n' => 'student_progress_student_user_id_foreign', 'rt' => 'users', 'rc' => 'id', 'od' => null],
            ['t' => 'student_progress', 'c' => 'subject_id', 'n' => 'student_progress_subject_id_foreign', 'rt' => 'subjects', 'rc' => 'id', 'od' => null],
            ['t' => 'students', 'c' => 'user_id', 'n' => 'students_user_id_foreign', 'rt' => 'users', 'rc' => 'id', 'od' => null],
            ['t' => 'study_resources', 'c' => 'institution_id', 'n' => 'study_resources_institution_id_foreign', 'rt' => 'institutions', 'rc' => 'id', 'od' => null],
            ['t' => 'subjects', 'c' => 'institution_id', 'n' => 'subjects_institution_id_foreign', 'rt' => 'institutions', 'rc' => 'id', 'od' => null],
            ['t' => 'users', 'c' => 'institution_id', 'n' => 'users_institution_id_foreign', 'rt' => 'institutions', 'rc' => 'id', 'od' => null],
        ];
    }

    /** FK que no existían antes: al revertir solo se eliminan. */
    private function nuevas(): array
    {
        return [
            ['t' => 'ai_recommendations', 'n' => 'ai_recommendations_institution_id_foreign'],
            ['t' => 'question_options', 'n' => 'question_options_institution_id_foreign'],
            ['t' => 'student_answers', 'n' => 'student_answers_institution_id_foreign'],
        ];
    }

    public function up(): void
    {
        $this->abortarSiHayHuerfanos();
        $this->aplicar($this->objetivo());
    }

    public function down(): void
    {
        foreach ($this->nuevas() as $fk) {
            DB::statement("ALTER TABLE {$fk['t']} DROP CONSTRAINT IF EXISTS {$fk['n']}");
        }

        $this->aplicar($this->previo());
    }

    private function aplicar(array $fks): void
    {
        foreach ($fks as $fk) {
            $onDelete = $fk['od'] ? " ON DELETE {$fk['od']}" : '';

            DB::statement("ALTER TABLE {$fk['t']} DROP CONSTRAINT IF EXISTS {$fk['n']}");
            DB::statement(
                "ALTER TABLE {$fk['t']} ADD CONSTRAINT {$fk['n']} "
                . "FOREIGN KEY ({$fk['c']}) REFERENCES {$fk['rt']}({$fk['rc']}){$onDelete}"
            );
        }
    }

    /**
     * Las 4 FK que pasan a `students(user_id)` y las 3 de `institution_id` son
     * más estrictas que las actuales. Si hubiera filas huérfanas el ALTER
     * fallaría a media migración; mejor abortar antes con un diagnóstico útil.
     */
    private function abortarSiHayHuerfanos(): void
    {
        $comprobaciones = [
            ['exam_attempts',      'student_user_id', 'students',     'user_id'],
            ['student_progress',   'student_user_id', 'students',     'user_id'],
            ['ai_recommendations', 'student_user_id', 'students',     'user_id'],
            ['group_students',     'student_user_id', 'students',     'user_id'],
            ['ai_recommendations', 'institution_id',  'institutions', 'id'],
            ['question_options',   'institution_id',  'institutions', 'id'],
            ['student_answers',    'institution_id',  'institutions', 'id'],
        ];

        $problemas = [];

        foreach ($comprobaciones as [$tabla, $col, $refT, $refC]) {
            $n = DB::selectOne(
                "SELECT count(*) AS total FROM {$tabla} h
                  WHERE h.{$col} IS NOT NULL
                    AND NOT EXISTS (SELECT 1 FROM {$refT} r WHERE r.{$refC} = h.{$col})"
            )->total;

            if ((int) $n > 0) {
                $problemas[] = "{$tabla}.{$col} → {$refT}({$refC}): {$n} filas huérfanas";
            }
        }

        if (!empty($problemas)) {
            throw new RuntimeException(
                'No se pueden aplicar las FK del modelo TFG, hay datos que las violan. '
                . 'Límpialos antes de migrar → ' . implode('; ', $problemas)
            );
        }
    }
};
