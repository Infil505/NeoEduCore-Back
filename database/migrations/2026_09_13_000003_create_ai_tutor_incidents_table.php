<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de incidencias del tutor IA (decisión D5).
 *
 * [173] compromete al sistema a «registrar incidencias» y pone un criterio de
 * calidad medible: más del **75 %** de los mensajes deben superar la validación.
 * Hasta aquí, un bloqueo por posible dato personal o por enlace fuera de la
 * lista blanca solo dejaba un `Log::warning`: no había ni tabla ni contador, así
 * que el criterio no se podía calcular. Un requisito que no se puede medir no
 * está cumplido, esté o esté escrito en el informe.
 *
 * **Lo que esta tabla NO guarda: el texto que causó la incidencia.** Es la
 * decisión importante del diseño. Un registro de bloqueos por datos personales
 * que almacenara el fragmento con el dato personal sería exactamente el fallo
 * que pretende evitar, y encima lo dejaría en una tabla que el superadministrador
 * —externo a la institución— puede consultar. Se guarda el **tipo**, el **punto**
 * del tutor donde ocurrió y a qué institución pertenece: suficiente para contar,
 * insuficiente para filtrar a nadie. Ver [394] y la Ley 8968.
 *
 * `student_user_id` sí se guarda, con `ON DELETE SET NULL`, porque el centro
 * necesita poder auditar su propio caso («este alumno insiste en escribir su
 * teléfono»). **Ningún endpoint lo expone hoy**: el del superadministrador
 * devuelve solo agregados, igual que `tutor-usage` hace con el docente.
 *
 * No lleva `updated_at`: una incidencia es un hecho pasado, no se edita.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "CREATE TYPE public.ai_incident_type AS ENUM
             ('pii', 'too_short', 'too_long', 'blocked_url', 'model_error')"
        );

        DB::statement("CREATE TYPE public.ai_incident_stage AS ENUM ('chat', 'diagnosis')");

        Schema::create('ai_tutor_incidents', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('institution_id');
            $table->uuid('student_user_id')->nullable();
            $table->uuid('session_id')->nullable();

            $table->timestamp('occurred_at')->useCurrent();

            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();

            // Borrar al alumno no borra la estadística del centro: la incidencia
            // ocurrió, y el recuento del criterio de [173] no debe encogerse
            // porque alguien se dé de baja.
            $table->foreign('student_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('session_id')->references('id')->on('ai_chat_sessions')->nullOnDelete();

            // El índice que sirve a la métrica: por centro y por fecha.
            $table->index(['institution_id', 'occurred_at'], 'ai_tutor_incidents_institution_occurred_index');
        });

        DB::statement('ALTER TABLE public.ai_tutor_incidents ADD COLUMN type public.ai_incident_type NOT NULL');
        DB::statement('ALTER TABLE public.ai_tutor_incidents ADD COLUMN stage public.ai_incident_stage NOT NULL');
        DB::statement('CREATE INDEX ai_tutor_incidents_type_index ON public.ai_tutor_incidents (type)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tutor_incidents');

        DB::statement('DROP TYPE IF EXISTS public.ai_incident_stage');
        DB::statement('DROP TYPE IF EXISTS public.ai_incident_type');
    }
};
