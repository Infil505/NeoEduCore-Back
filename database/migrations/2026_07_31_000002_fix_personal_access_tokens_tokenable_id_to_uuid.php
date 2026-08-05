<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Corrige `personal_access_tokens.tokenable_id` de bigint a uuid.
     *
     * La migración original de Sanctum usaba `morphs('tokenable')`, que genera
     * bigint. Como `users.id` es uuid, Sanctum no puede resolver el tokenable y
     * la autenticación entera se cae. La BD de producción ya fue corregida a
     * mano en su día; esta migración deja el arreglo en el historial para que
     * cualquier entorno nuevo o rezagado quede igual.
     *
     * Es IDEMPOTENTE: si la columna ya es uuid no toca nada.
     */
    public function up(): void
    {
        if ($this->tipoActual() === 'uuid') {
            return; // Ya corregida (producción, y cualquier entorno al día).
        }

        DB::transaction(function () {
            // Los tokens existentes apuntan a ids bigint que no existen entre
            // los users uuid: son basura inservible y además bloquean el cast.
            // Vaciarlos solo obliga a re-loguearse.
            $huerfanos = DB::table('personal_access_tokens')->count();
            if ($huerfanos > 0) {
                DB::table('personal_access_tokens')->delete();
            }

            DB::statement('
                ALTER TABLE personal_access_tokens
                ALTER COLUMN tokenable_id TYPE uuid USING NULL::uuid
            ');
        });
    }

    /**
     * Revierte a bigint. Ojo: volver atrás reintroduce el bug que rompe
     * Sanctum; se mantiene solo para que el rollback sea honesto.
     */
    public function down(): void
    {
        if ($this->tipoActual() !== 'uuid') {
            return;
        }

        DB::transaction(function () {
            DB::table('personal_access_tokens')->delete();

            DB::statement('
                ALTER TABLE personal_access_tokens
                ALTER COLUMN tokenable_id TYPE bigint USING NULL::bigint
            ');
        });
    }

    private function tipoActual(): ?string
    {
        $col = DB::selectOne("
            SELECT data_type
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = 'personal_access_tokens'
              AND column_name = 'tokenable_id'
        ");

        return $col?->data_type;
    }
};
