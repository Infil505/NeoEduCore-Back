<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nuevo tipo de incidencia: `prompt_injection`.
 *
 * Las cinco anteriores describen lo que hace **el modelo** (se equivoca, se
 * desborda, cita un enlace que no toca). Esta describe lo que intenta **el
 * alumno**: reescribir las instrucciones del tutor. Se registra en la entrada,
 * antes de llamar a OpenAI, así que ni siquiera llega a haber respuesta.
 *
 * Queda fuera de `AiIncidentType::deValidacion()` a propósito — ver el enum.
 */
return new class extends Migration
{
    /**
     * `ALTER TYPE ... ADD VALUE` no puede ir dentro de la misma transacción que
     * después use el valor nuevo, y PostgreSQL sí soporta DDL transaccional, así
     * que Laravel envolvería la migración por defecto. Se sale de la transacción.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(
            "ALTER TYPE public.ai_incident_type ADD VALUE IF NOT EXISTS 'prompt_injection'"
        );
    }

    /**
     * PostgreSQL no sabe quitar un valor de un enum, así que el tipo se
     * reconstruye. Las incidencias de este tipo se pierden: son estadística de
     * intentos bloqueados, no datos del centro, y sin el valor en el tipo no hay
     * dónde guardarlas.
     */
    public function down(): void
    {
        DB::statement("DELETE FROM public.ai_tutor_incidents WHERE type = 'prompt_injection'");

        DB::statement('ALTER TYPE public.ai_incident_type RENAME TO ai_incident_type_old');
        DB::statement(
            "CREATE TYPE public.ai_incident_type AS ENUM
             ('pii', 'too_short', 'too_long', 'blocked_url', 'model_error')"
        );
        DB::statement(
            'ALTER TABLE public.ai_tutor_incidents
                ALTER COLUMN type TYPE public.ai_incident_type
                USING type::text::public.ai_incident_type'
        );
        DB::statement('DROP TYPE public.ai_incident_type_old');
    }
};
