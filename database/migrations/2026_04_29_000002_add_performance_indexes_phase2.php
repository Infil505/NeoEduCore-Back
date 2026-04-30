<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices de rendimiento fase 2 para soportar 200+ usuarios concurrentes.
 *
 * Racional por tabla:
 * - ai_recommendations: índice compuesto (student+exam+subject) para el COUNT de
 *   regeneraciones en ExamAttemptController::regenerateRecommendations (3 filtros juntos).
 * - student_answers: índice en review_status para filtrar respuestas pendientes de revisión.
 * - exam_attempts: índice en grade_status para filtrar intentos pendientes de calificación.
 * - ai_chat_sessions: índice en institution_id ya existe en schema; agregar en student+ended_at
 *   para la query de sesiones activas del tutor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_recommendations', function (Blueprint $table) {
            $table->index(
                ['student_user_id', 'exam_id', 'subject_id'],
                'idx_ai_recs_regen_filter'
            );
        });

        Schema::table('student_answers', function (Blueprint $table) {
            $table->index('review_status', 'idx_answers_review_status');
        });

        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->index('grade_status', 'idx_attempts_grade_status');
        });

        Schema::table('ai_chat_sessions', function (Blueprint $table) {
            $table->index(['student_user_id', 'ended_at'], 'idx_chat_sessions_active');
        });
    }

    public function down(): void
    {
        Schema::table('ai_recommendations', fn($t) => $t->dropIndex('idx_ai_recs_regen_filter'));
        Schema::table('student_answers',    fn($t) => $t->dropIndex('idx_answers_review_status'));
        Schema::table('exam_attempts',      fn($t) => $t->dropIndex('idx_attempts_grade_status'));
        Schema::table('ai_chat_sessions',   fn($t) => $t->dropIndex('idx_chat_sessions_active'));
    }
};
