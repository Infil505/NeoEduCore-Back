<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cuántas veces se ha redactado con IA la lectura de un examen (11/10/2026).
 *
 * El docente puede pedir «Redactar con IA» solo un número limitado de veces por examen
 * (`openai.docente.analysis_ai_per_exam`). Cuenta las redacciones que llegaron al modelo; devolver lo ya guardado no cuenta.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE public.exam_analysis_narratives ADD COLUMN ai_generations smallint DEFAULT 0 NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE public.exam_analysis_narratives DROP COLUMN IF EXISTS ai_generations');
    }
};
