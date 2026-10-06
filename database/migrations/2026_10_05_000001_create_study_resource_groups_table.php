<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A qué aulas llega cada recurso de estudio (05/10/2026).
 *
 * Hasta aquí un recurso era del centro entero: cualquier estudiante veía los de
 * cualquier docente. La regla del centro es que un recurso es una actividad del
 * docente y llega **solo a las aulas que él elige** de las que tiene asignadas
 * (igual que un examen con `exam_targets`).
 *
 * Los recursos que ya existen se quedan **sin aula**, y por tanto sin alumnado
 * que los vea, hasta que su docente los envíe a un aula. Es deliberado, como en
 * `teacher_assignments`: el único estado de partida en el que se sabe que no
 * queda visibilidad heredada sin revisar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_resource_groups', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('institution_id');
            $table->uuid('study_resource_id');
            $table->uuid('group_id');

            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();
            // Borrar el recurso o el aula retira el envío; ninguno de los dos
            // se pierde por el otro.
            $table->foreign('study_resource_id')->references('id')->on('study_resources')->cascadeOnDelete();
            $table->foreign('group_id')->references('id')->on('groups')->cascadeOnDelete();

            $table->unique(['study_resource_id', 'group_id'], 'study_resource_groups_unico');
            $table->index('group_id', 'idx_study_resource_groups_group');
        });

        // Como el resto de tablas de dominio (ver 2026_09_13_000004).
        DB::statement('ALTER TABLE public.study_resource_groups ENABLE ROW LEVEL SECURITY');
    }

    public function down(): void
    {
        Schema::dropIfExists('study_resource_groups');
    }
};
