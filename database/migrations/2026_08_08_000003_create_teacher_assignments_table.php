<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asignación explícita docente → grupo → materia.
 *
 * Antes de esta tabla, el vínculo docente-estudiante se **derivaba** de la
 * autoría del examen: «mis estudiantes» eran los de cualquier grupo al que yo
 * hubiera apuntado un examen. Como nada impedía apuntar a un grupo ajeno
 * (`ExamController::store()` solo validaba el tenant), bastaba un borrador
 * —invisible para el alumnado— para concederse acceso al progreso, los informes
 * y las recomendaciones de IA de cualquier grupo de la institución.
 *
 * A partir de aquí la relación es al revés: la asignación la crea el admin y el
 * examen tiene que respetarla. Nadie amplía su propio alcance.
 *
 * La tabla nace **vacía a propósito**: al desplegar, ningún docente ve
 * estudiantes hasta que el admin cargue las asignaciones. Es disruptivo por un
 * rato, pero es el único estado de partida en el que se sabe que no queda
 * acceso heredado sin revisar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('institution_id')->index();
            $table->uuid('teacher_user_id');
            $table->uuid('group_id');
            $table->uuid('subject_id');
            $table->timestampTz('assigned_at')->useCurrent();
            $table->timestamps();

            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();

            // El docente es un users, no un students: no hay tabla propia de
            // docentes, el rol vive en users.user_type.
            $table->foreign('teacher_user_id')->references('id')->on('users')->cascadeOnDelete();

            // Borrar el grupo o la materia retira la asignación: sin grupo no hay
            // a quién dar clase, y sin materia la fila no significa nada.
            $table->foreign('group_id')->references('id')->on('groups')->cascadeOnDelete();
            $table->foreign('subject_id')->references('id')->on('subjects')->cascadeOnDelete();

            $table->unique(['teacher_user_id', 'group_id', 'subject_id'], 'teacher_assignments_unico');

            // El filtro caliente: «los grupos de este docente», que se resuelve en
            // cada petición de informes, progreso y recomendaciones.
            $table->index(['teacher_user_id', 'institution_id'], 'teacher_assignments_docente_idx');
            $table->index('group_id', 'teacher_assignments_grupo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_assignments');
    }
};
