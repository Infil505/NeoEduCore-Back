<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Verificación de la clave de cada pregunta (11/10/2026).
 *
 * Un docente puede equivocarse al marcar la respuesta correcta. Cuando el tutor habla de una pregunta que el
 * estudiante falló, una llamada aparte al modelo comprueba si esa clave es realmente correcta y guarda aquí
 * SOLO el veredicto (`correcta`, `incorrecta` o `dudosa`), nunca la respuesta.
 *
 *  - `key_check_hash`: huella del enunciado, las opciones y la clave con las que se verificó. Si el docente corrige la
 *    pregunta, la huella cambia y se vuelve a verificar; si no, no se paga otra llamada.
 *  - `key_check_result`: el veredicto.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE public.questions ADD COLUMN key_check_hash character varying(32)');
        DB::statement('ALTER TABLE public.questions ADD COLUMN key_check_result character varying(12)');
        DB::statement('ALTER TABLE public.questions ADD COLUMN key_checked_at timestamp(0) without time zone');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE public.questions DROP COLUMN IF EXISTS key_checked_at, DROP COLUMN IF EXISTS key_check_result, DROP COLUMN IF EXISTS key_check_hash');
    }
};
