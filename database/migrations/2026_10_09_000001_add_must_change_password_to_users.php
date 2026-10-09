<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `users.must_change_password`: la cuenta entró con una contraseña TEMPORAL y su
 * dueño tiene que cambiarla antes de usar nada más (decisión del 09/10/2026).
 *
 * Toda cuenta nueva (alta individual, carga masiva, administrador de centro)
 * recibe una contraseña temporal aleatoria por correo y nace activa con esta
 * marca en true. Hasta que la cambia, la API solo le deja ver su sesión, cerrar
 * sesión y cambiar la contraseña (`ExigeCambioDeClave`).
 *
 * Default false: las cuentas que ya existen no se ven afectadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
