<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige dos bugs reales de producción descubiertos al alinear el esquema de
 * tests con el de migraciones (antes el SQL hecho a mano los ocultaba):
 *
 * 1. Pivotes sin DEFAULT en `id`: group_students, exam_targets y
 *    student_answer_options se insertan vía sync()/attach() o raw DB sin pasar
 *    por HasUuids, así que `id` llegaba NULL → "not null violation". El esquema
 *    original (01_schema.sql) tenía DEFAULT gen_random_uuid(); las migraciones no.
 *
 * 2. CHECK obsoleto en student_answers.review_status: la conversión a enum
 *    nativo (C1) dejó el CHECK viejo `('auto_graded','needs_review')`, que NO
 *    incluye 'reviewed'. Revisar una respuesta (status=reviewed) violaba el CHECK
 *    → 500. El enum nativo ya valida los valores, el CHECK sobra.
 */
return new class extends Migration
{
    private array $pivots = ['group_students', 'exam_targets', 'student_answer_options'];

    public function up(): void
    {
        foreach ($this->pivots as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN id SET DEFAULT gen_random_uuid()");
        }

        DB::statement('ALTER TABLE student_answers DROP CONSTRAINT IF EXISTS student_answers_review_status_check');
    }

    public function down(): void
    {
        foreach ($this->pivots as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN id DROP DEFAULT");
        }
        // El CHECK obsoleto no se recrea (era incorrecto).
    }
};
