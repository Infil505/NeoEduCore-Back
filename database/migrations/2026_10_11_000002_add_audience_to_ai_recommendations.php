<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A quién va dirigida una recomendación de IA (11/10/2026).
 *
 * Hasta aquí todas se escribían PARA EL ESTUDIANTE («te recomiendo…») y las veía el estudiante, incluidas
 * las que un docente pedía a mano. Pero el plan del tutor que el docente ve en analíticas es otra cosa:
 * consejo PARA EL DOCENTE sobre un estudiante (tercera persona, acciones para la clase). Mezclarlas
 * enseñaría al estudiante textos que no son para él.
 *
 *  - `student` (por defecto: todo lo anterior): lo que recibe el estudiante.
 *  - `teacher`: consejo para el docente; el estudiante nunca lo lee.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE ai_recommendations ADD COLUMN audience varchar(16) NOT NULL DEFAULT 'student'");
        DB::statement("ALTER TABLE ai_recommendations ADD CONSTRAINT ai_recommendations_audience_check CHECK (audience IN ('student', 'teacher'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ai_recommendations DROP CONSTRAINT IF EXISTS ai_recommendations_audience_check');
        DB::statement('ALTER TABLE ai_recommendations DROP COLUMN IF EXISTS audience');
    }
};
