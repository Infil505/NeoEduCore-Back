<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `ai_recommendations.subject_id`: de `SET NULL` a `CASCADE`.
 *
 * La FK pedía `ON DELETE SET NULL` (modelo del TFG) pero la columna es `NOT NULL`: al borrar una
 * materia con recomendaciones, PostgreSQL intentaba ponerla a NULL y fallaba, y
 * `DELETE /api/subjects/{id}` devolvía **500**. Se decide (11/10/2026) que borrar una materia arrastra
 * todo lo suyo, como ya hacía con sus exámenes, el progreso, las matrículas y las asignaciones:
 * las recomendaciones de una materia que ya no existe no tienen a qué referirse.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->recrear('CASCADE');
    }

    public function down(): void
    {
        $this->recrear('SET NULL');
    }

    private function recrear(string $alBorrar): void
    {
        DB::statement('ALTER TABLE ai_recommendations DROP CONSTRAINT IF EXISTS ai_recommendations_subject_id_foreign');
        DB::statement(
            'ALTER TABLE ai_recommendations ADD CONSTRAINT ai_recommendations_subject_id_foreign '
            . "FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE {$alBorrar}"
        );
    }
};
