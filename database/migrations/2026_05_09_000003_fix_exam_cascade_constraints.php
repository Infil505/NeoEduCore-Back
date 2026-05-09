<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corrige las FK de la tabla exams que carecían de ON DELETE:
 *
 * - subject_id → ON DELETE CASCADE
 *   Al borrar una materia se eliminan todos sus exámenes (y en cascada sus
 *   preguntas, opciones, intentos y respuestas).
 *
 * - created_by_teacher_id → nullable + ON DELETE SET NULL
 *   Al borrar un usuario-profesor el examen sobrevive pero pierde la
 *   referencia al autor (campo queda en NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropForeign(['subject_id']);
            $table->dropForeign(['created_by_teacher_id']);

            // Hace nullable el campo antes de aplicar SET NULL
            $table->uuid('created_by_teacher_id')->nullable()->change();

            $table->foreign('subject_id')
                ->references('id')->on('subjects')
                ->onDelete('cascade');

            $table->foreign('created_by_teacher_id')
                ->references('id')->on('users')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropForeign(['subject_id']);
            $table->dropForeign(['created_by_teacher_id']);

            // Revierte a NOT NULL (puede fallar si ya hay NULLs)
            $table->uuid('created_by_teacher_id')->nullable(false)->change();

            $table->foreign('subject_id')->references('id')->on('subjects');
            $table->foreign('created_by_teacher_id')->references('id')->on('users');
        });
    }
};
