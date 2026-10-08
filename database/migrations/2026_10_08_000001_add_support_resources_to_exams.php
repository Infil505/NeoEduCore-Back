<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enlaces de apoyo del examen (08/10/2026): vídeos, textos…
 *
 * Amplía `video_url`: el docente puede dejar varios enlaces —hasta
 * `exams.max_support_resources`— y el tutor los usa como recurso al armar las
 * recomendaciones del alumno, antes que el catálogo del centro.
 *
 * Forma de cada elemento: `{"type": "video"|"text", "url": "...", "title": "..."}`.
 * Los elige el docente, nunca el modelo: con menores, la lista blanca solo
 * garantiza el dominio, no el contenido.
 *
 * Nullable: es opcional, y los exámenes existentes no lo tienen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->jsonb('support_resources')->nullable()->after('video_url');
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn('support_resources');
        });
    }
};
