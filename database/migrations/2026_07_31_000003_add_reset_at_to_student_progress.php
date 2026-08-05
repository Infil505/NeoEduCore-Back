<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca de corte del progreso, para repitentes.
     *
     * `recalcFromAttempts` promedia TODOS los intentos enviados del estudiante
     * en esa materia. Sin un corte, poner el progreso a cero no sirve de nada:
     * el siguiente examen (o la siguiente revisión de respuesta) recalcula desde
     * el historial completo y lo restaura.
     *
     * Con `reset_at`, el recálculo solo considera intentos posteriores a la
     * marca. Los intentos viejos NO se borran: siguen para auditoría e
     * historial académico, simplemente dejan de contar para el dominio actual.
     */
    public function up(): void
    {
        Schema::table('student_progress', function (Blueprint $table) {
            // Sin ->after(): es no-op en PostgreSQL y la columna queda al final.
            // Ponerlo haría creer que el orden del artefacto de esquema difiere.
            $table->timestamp('reset_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('student_progress', function (Blueprint $table) {
            $table->dropColumn('reset_at');
        });
    }
};
