<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modo asíncrono del chat del tutor (O7).
 *
 * Con `async: true` la respuesta del modelo se genera en la cola y el frontend
 * la recoge consultando la sesión. Esta marca dice si hay una respuesta en
 * camino: se pone al encolar y la quita el propio UPDATE que anexa el turno.
 *
 * Va en la base y no en la caché a propósito: la web y el worker pueden correr
 * en contenedores distintos, y una caché en fichero no se comparte entre ellos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chat_sessions', function (Blueprint $table) {
            $table->timestamp('awaiting_reply_since')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_chat_sessions', function (Blueprint $table) {
            $table->dropColumn('awaiting_reply_since');
        });
    }
};
