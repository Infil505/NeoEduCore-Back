<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. exam_attempts: campos de pausa
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->timestamp('paused_at')->nullable()->after('submitted_at');
            $table->integer('total_paused_seconds')->default(0)->after('paused_at');
        });

        // 2. students: estilo de aprendizaje (PostgreSQL ENUM nativo)
        DB::statement("DO \$\$ BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'learning_style') THEN
                CREATE TYPE learning_style AS ENUM ('visual','auditivo','lector');
            END IF;
        END \$\$");
        DB::statement("ALTER TABLE students ADD COLUMN IF NOT EXISTS learning_style learning_style DEFAULT NULL");

        // 3. ai_chat_sessions: historial de tutor IA conversacional
        Schema::create('ai_chat_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->uuid('student_user_id');
            $table->uuid('subject_id')->nullable();
            $table->uuid('exam_id')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->foreign('institution_id')->references('id')->on('institutions')->onDelete('cascade');
            $table->foreign('student_user_id')->references('user_id')->on('students')->onDelete('cascade');
            $table->foreign('subject_id')->references('id')->on('subjects')->onDelete('set null');
            $table->foreign('exam_id')->references('id')->on('exams')->onDelete('set null');

            $table->index(['student_user_id', 'updated_at']);
        });
        // messages como jsonb (mejor rendimiento para consultas JSON en PostgreSQL)
        DB::statement("ALTER TABLE ai_chat_sessions ADD COLUMN messages jsonb NOT NULL DEFAULT '[]'");
    }

    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropColumn(['paused_at', 'total_paused_seconds']);
        });

        DB::statement("ALTER TABLE students DROP COLUMN IF EXISTS learning_style");
        DB::statement("DROP TYPE IF EXISTS learning_style");

        Schema::dropIfExists('ai_chat_sessions');
    }
};
