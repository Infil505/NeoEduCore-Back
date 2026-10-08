<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos del centro (07/10/2026).
 *
 * Hasta aquí un evento siempre iba a una sección (`group_id`) y lo creaba un
 * docente. El administrador puede ahora publicar avisos para toda la
 * institución, eligiendo a quién: `students`, `teachers` o `all`. Esos avisos
 * no tienen sección (`group_id` NULL) y se identifican por `audience`.
 *
 * Los eventos de sección (los del docente) dejan `audience` en NULL: su
 * visibilidad sigue decidiéndola `group_id`, como antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->string('audience', 16)->nullable()->after('group_id');
            $table->index(['institution_id', 'audience'], 'idx_calendar_events_audience');
        });

        DB::statement("ALTER TABLE calendar_events ADD CONSTRAINT calendar_events_audience_check CHECK (audience IS NULL OR audience IN ('students', 'teachers', 'all'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE calendar_events DROP CONSTRAINT IF EXISTS calendar_events_audience_check');

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropIndex('idx_calendar_events_audience');
            $table->dropColumn('audience');
        });
    }
};
