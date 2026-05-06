<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega índices faltantes en columnas de búsqueda frecuente.
 * PostgreSQL NO crea índices automáticamente para claves foráneas.
 */
return new class extends Migration
{
    public function up(): void
    {
        // users — tenant scope filtra por institution_id en cada request
        Schema::table('users', function (Blueprint $table) {
            $table->index('institution_id', 'idx_users_institution');
        });

        // students — filtros frecuentes en index() y bulk queries
        Schema::table('students', function (Blueprint $table) {
            $table->index('institution_id',  'idx_students_institution');
            $table->index('grade',           'idx_students_grade');
            $table->index('status',          'idx_students_status');
        });

        // questions — hasMany desde Exam, carga en cada examen abierto
        Schema::table('questions', function (Blueprint $table) {
            $table->index(['exam_id', 'order_index'], 'idx_questions_exam_order');
        });

        // exam_attempts — tabla más consultada: reportes, intentos activos, recalculos
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->index('exam_id',          'idx_attempts_exam');
            $table->index('student_user_id',  'idx_attempts_student');
            $table->index('submitted_at',     'idx_attempts_submitted');
            $table->index('institution_id',   'idx_attempts_institution');
            // Compuesto para ReportController::examResults (exam + submitted filter juntos)
            $table->index(['exam_id', 'submitted_at'], 'idx_attempts_exam_submitted');
        });

        // student_answers — hasMany desde ExamAttempt
        Schema::table('student_answers', function (Blueprint $table) {
            $table->index('attempt_id',  'idx_answers_attempt');
            $table->index('question_id', 'idx_answers_question');
        });

        // student_answer_options — pivot de respuestas seleccionadas
        Schema::table('student_answer_options', function (Blueprint $table) {
            $table->index('student_answer_id', 'idx_answer_options_answer');
        });

        // student_progress — consultado en me(), recalcFromAttempts(), index()
        Schema::table('student_progress', function (Blueprint $table) {
            $table->index('institution_id',   'idx_progress_institution');
            $table->index('student_user_id',  'idx_progress_student');
            $table->index('subject_id',       'idx_progress_subject');
            // Unicidad: un registro por (alumno, materia) — refuerza el updateOrCreate
            $table->unique(['student_user_id', 'subject_id'], 'uniq_progress_student_subject');
        });

        // ai_recommendations — consultado por docentes y en regenerateRecommendations
        Schema::table('ai_recommendations', function (Blueprint $table) {
            $table->index('institution_id',  'idx_ai_institution');
            $table->index('student_user_id', 'idx_ai_student');
            $table->index('exam_id',         'idx_ai_exam');
            $table->index('subject_id',      'idx_ai_subject');
        });

        // group_students — pivot: búsquedas por grupo y por alumno
        Schema::table('group_students', function (Blueprint $table) {
            $table->index('group_id',          'idx_group_students_group');
            $table->index('student_user_id',   'idx_group_students_student');
            $table->unique(['group_id', 'student_user_id'], 'uniq_group_students');
        });

        // exam_targets — pivot: examen ↔ grupo
        Schema::table('exam_targets', function (Blueprint $table) {
            $table->index('exam_id',  'idx_exam_targets_exam');
            $table->index('group_id', 'idx_exam_targets_group');
        });
    }

    public function down(): void
    {
        Schema::table('users',                 fn ($t) => $t->dropIndex('idx_users_institution'));
        Schema::table('students',              fn ($t) => $t->dropIndex(['idx_students_institution','idx_students_grade','idx_students_status']));
        Schema::table('questions',             fn ($t) => $t->dropIndex('idx_questions_exam_order'));
        Schema::table('exam_attempts',         fn ($t) => $t->dropIndex(['idx_attempts_exam','idx_attempts_student','idx_attempts_submitted','idx_attempts_institution','idx_attempts_exam_submitted']));
        Schema::table('student_answers',       fn ($t) => $t->dropIndex(['idx_answers_attempt','idx_answers_question']));
        Schema::table('student_answer_options',fn ($t) => $t->dropIndex('idx_answer_options_answer'));
        Schema::table('student_progress',      fn ($t) => $t->dropIndex(['idx_progress_institution','idx_progress_student','idx_progress_subject']));
        Schema::table('student_progress',      fn ($t) => $t->dropUnique('uniq_progress_student_subject'));
        Schema::table('ai_recommendations',    fn ($t) => $t->dropIndex(['idx_ai_institution','idx_ai_student','idx_ai_exam','idx_ai_subject']));
        Schema::table('group_students',        fn ($t) => $t->dropIndex(['idx_group_students_group','idx_group_students_student']));
        Schema::table('group_students',        fn ($t) => $t->dropUnique('uniq_group_students'));
        Schema::table('exam_targets',          fn ($t) => $t->dropIndex(['idx_exam_targets_exam','idx_exam_targets_group']));
    }
};
