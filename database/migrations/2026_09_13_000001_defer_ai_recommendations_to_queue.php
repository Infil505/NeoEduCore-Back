<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recomendaciones post-examen generadas por IA en segundo plano (decisión D1).
 *
 * Hasta aquí, la entrega de un examen guardaba 1 o 2 recomendaciones de texto
 * fijo elegidas por un `if/elseif/else` sobre el porcentaje, y la IA solo
 * intervenía si el alumno pulsaba «regenerar». El informe ([122], [222], [255],
 * [736]) promete que el tutor analiza los resultados y genera recomendaciones
 * personalizadas, así que la generación pasa a la cola.
 *
 * **El disparador no es la entrega, sino la consulta de resultados.** La entrega
 * sigue respondiendo al instante con la heurística; cuando el alumno abre sus
 * resultados se encola el análisis de la IA, que sustituye ese lote. Dos razones:
 * el pico real del sistema es la entrega simultánea de una clase entera
 * (`ANALISIS_CONCURRENCIA.md`), y solo se paga API por el alumno que de verdad
 * va a leer las recomendaciones.
 *
 * Tres piezas de esquema hacen falta para eso:
 *
 * 1. **`ai_recommendations.attempt_id`.** Es la que faltaba para poder hacer
 *    nada limpio. Sin ella, un lote de recomendaciones solo se podía identificar
 *    por (estudiante, examen, materia) y una ventana de `generated_at` posterior
 *    a la entrega — así se contaba el límite de regeneraciones, con precisión de
 *    segundo. El job necesita saber **exactamente** qué filas sustituye; si no,
 *    el alumno acaba viendo el lote heurístico y el de IA a la vez.
 *
 * 2. **`ai_recommendations.generated_by`.** Distingue el texto de plantilla del
 *    que salió del modelo. Sin este campo, la API no puede respaldar lo que el
 *    informe afirma, el frontend no puede avisar de qué está mirando el alumno
 *    ([397], decisión D4) y nadie puede medir qué proporción de entregas acabó
 *    con análisis real.
 *
 * 3. **`exam_attempts.ai_recommendations_status`.** El estado «en preparación»
 *    que el frontend necesita para no dar por definitivo lo que va a cambiar, y
 *    a la vez el cerrojo que impide encolar dos veces el mismo análisis cuando
 *    el alumno abre sus resultados, recarga y vuelve a abrirlos.
 *    `NULL` significa «a este intento nunca se le encoló nada»: es el estado de
 *    los intentos anteriores a este cambio y de los recién entregados que nadie
 *    ha consultado todavía. Es justo el valor que dispara el encolado.
 *
 * Las filas existentes se quedan con `attempt_id` nulo: el vínculo no se puede
 * reconstruir sin inventarlo, y son datos de pruebas de un sistema que aún no
 * tiene usuarios reales.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Enums nativos, como el resto del esquema desde la migración del 24/06.
        DB::statement("CREATE TYPE public.ai_generation_source AS ENUM ('heuristic', 'ai')");
        DB::statement("CREATE TYPE public.ai_recommendations_status AS ENUM ('preparing', 'ready', 'failed')");

        Schema::table('ai_recommendations', function (Blueprint $table) {
            $table->uuid('attempt_id')->nullable()->after('exam_id');

            // Borrar el intento se lleva sus recomendaciones: describen ese
            // intento y solo ese. Es la misma regla que ya cascadea de examen a
            // resultados.
            $table->foreign('attempt_id')->references('id')->on('exam_attempts')->cascadeOnDelete();

            // El job busca por aquí el lote que va a sustituir.
            $table->index('attempt_id', 'ai_recommendations_attempt_id_index');
        });

        DB::statement(
            "ALTER TABLE public.ai_recommendations
             ADD COLUMN generated_by public.ai_generation_source NOT NULL DEFAULT 'heuristic'"
        );

        DB::statement(
            'ALTER TABLE public.exam_attempts
             ADD COLUMN ai_recommendations_status public.ai_recommendations_status NULL'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE public.exam_attempts DROP COLUMN IF EXISTS ai_recommendations_status');
        DB::statement('ALTER TABLE public.ai_recommendations DROP COLUMN IF EXISTS generated_by');

        Schema::table('ai_recommendations', function (Blueprint $table) {
            $table->dropForeign(['attempt_id']);
            $table->dropIndex('ai_recommendations_attempt_id_index');
            $table->dropColumn('attempt_id');
        });

        DB::statement('DROP TYPE IF EXISTS public.ai_recommendations_status');
        DB::statement('DROP TYPE IF EXISTS public.ai_generation_source');
    }
};
