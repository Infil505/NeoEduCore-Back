<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notificaciones dentro de la app (O1): hoy, «tienes un examen disponible».
 *
 * Es la tabla estándar de `Notifiable`/canal `database` de Laravel, con dos
 * cambios deliberados respecto al stub de `make:notifications-table`:
 *
 * 1. **`notifiable_id` es uuid**, no `morphs()` (bigint): aquí los ids de
 *    usuario son uuid. Con el stub, el primer insert fallaría.
 * 2. **FK real a `users(id)` con CASCADE.** Una relación polimórfica no admite
 *    FK, y entonces borrar un usuario dejaría sus avisos huérfanos. Como lo
 *    único notificable del sistema es `User`, se declara la FK y la base hace
 *    la limpieza. Si algún día se notifica a otra cosa, esta FK hay que
 *    replantearla.
 *
 * El canal es solo la app, no el correo: el alumnado tiene entre 6 y 12 años y
 * su cuenta puede no tener un buzón que alguien lea (decisión del 03/10/2026).
 *
 * Se trata como tabla de framework —sin `institution_id` ni `TenantScoped`—:
 * se consulta siempre por el usuario autenticado, que es lo que la aísla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('notifiable_type');
            $table->uuid('notifiable_id');
            $table->jsonb('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->foreign('notifiable_id')->references('id')->on('users')->cascadeOnDelete();

            // La consulta caliente: «mis avisos», y el contador de no leídos.
            $table->index(['notifiable_id', 'read_at'], 'notifications_destinatario_idx');
        });

        // Igual que el resto (D8): cierra la tabla a la API automática de Supabase.
        DB::statement('ALTER TABLE public.notifications ENABLE ROW LEVEL SECURITY');
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
