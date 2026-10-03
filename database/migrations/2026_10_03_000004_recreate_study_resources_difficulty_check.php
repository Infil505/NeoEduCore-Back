<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vuelve a crear `study_resources_difficulty_check` tal como la crea la
 * migración original (03/10/2026).
 *
 * En la base remota la restricción apareció recreada ese día por fuera de las
 * migraciones —entre las 11:31 y las 12:00, sin que se sepa por quién—: misma
 * regla, pero escrita de otra forma, así que `schema:dump-sql` la volcaba
 * distinta y `schema:check-drift` lo marcaba como drift.
 *
 * La expresión es literalmente la que genera `->enum()` de Laravel en
 * `2026_02_25_000004` (`"difficulty" in (...)`), para que PostgreSQL la guarde
 * y la vuelque igual que en una base migrada desde cero. Mismos tres valores:
 * no cambia qué filas son válidas.
 *
 * `DROP … IF EXISTS` porque en una base limpia la restricción ya existe con el
 * mismo nombre y en otra alterada podría no estar.
 */
return new class extends Migration
{
    private const CHECK = "CHECK (\"difficulty\" in ('basic', 'intermediate', 'advanced'))";

    public function up(): void
    {
        DB::statement('ALTER TABLE public.study_resources DROP CONSTRAINT IF EXISTS study_resources_difficulty_check');
        DB::statement('ALTER TABLE public.study_resources ADD CONSTRAINT study_resources_difficulty_check ' . self::CHECK);
    }

    /** Deja la misma restricción: no hay un estado anterior distinto que restaurar. */
    public function down(): void
    {
        $this->up();
    }
};
