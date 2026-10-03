<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vídeo de apoyo opcional del examen (03/10/2026).
 *
 * Lo pone el docente al crear o editar el examen, y el tutor se lo entrega al
 * alumnado de estilo `visual` o `auditivo` cuando habla de ese examen y en sus
 * recomendaciones. Solo YouTube (`App\Rules\UrlDeVideo`).
 *
 * Lo elige el docente y no el modelo a propósito: con menores de 6 a 12 años,
 * un enlace que propusiera la IA sería contenido que nadie revisó — y la lista
 * blanca solo comprueba el dominio, no lo que hay en el vídeo.
 *
 * Nullable: es opcional, y los exámenes existentes no lo tienen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->string('video_url', 255)->nullable()->after('instructions');
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn('video_url');
        });
    }
};
