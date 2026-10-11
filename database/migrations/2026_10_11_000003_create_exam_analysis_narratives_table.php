<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La lectura del análisis de un examen redactada por IA, guardada (11/10/2026).
 *
 * «Redactar con IA» en analíticas costaba una llamada a OpenAI cada vez que hacía falta y solo se recordaba 12 horas
 * en la caché (un archivo temporal): el docente volvía a entrar, veía la versión «Calculada» y tenía que pedirla de
 * nuevo. Aquí queda la última redactada de cada examen:
 *
 *  - una fila por examen (`exam_id` único): la última sustituye a la anterior;
 *  - `fingerprint`: huella de los datos con los que se redactó. Si ya no coincide con la de ahora (hubo entregas o
 *    revisiones nuevas) el texto se sigue mostrando, marcado como desactualizado, y solo se vuelve a redactar a petición.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE public.exam_analysis_narratives (
                id uuid DEFAULT gen_random_uuid() NOT NULL,
                institution_id uuid NOT NULL,
                exam_id uuid NOT NULL,
                fingerprint character varying(32) NOT NULL,
                narrative jsonb NOT NULL,
                generated_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
                created_at timestamp(0) without time zone,
                updated_at timestamp(0) without time zone
            )
        SQL);

        DB::statement('ALTER TABLE ONLY public.exam_analysis_narratives ADD CONSTRAINT exam_analysis_narratives_pkey PRIMARY KEY (id)');
        DB::statement('ALTER TABLE ONLY public.exam_analysis_narratives ADD CONSTRAINT exam_analysis_narratives_exam_id_unique UNIQUE (exam_id)');
        DB::statement('ALTER TABLE ONLY public.exam_analysis_narratives ADD CONSTRAINT exam_analysis_narratives_exam_id_foreign FOREIGN KEY (exam_id) REFERENCES public.exams(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE ONLY public.exam_analysis_narratives ADD CONSTRAINT exam_analysis_narratives_institution_id_foreign FOREIGN KEY (institution_id) REFERENCES public.institutions(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE public.exam_analysis_narratives ENABLE ROW LEVEL SECURITY');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS public.exam_analysis_narratives');
    }
};
