<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Metadatos curriculares de los ítems y materia de los recursos (decisión D2).
 *
 * El informe promete un banco de ítems «con metadatos de tema, indicador y
 * dificultad» ([171], [222]) y personalización por área concreta —«si un
 * estudiante tiene dificultad en comprensión de lectura…» ([263], [276])—. El
 * sistema solo sabía de porcentaje de dominio **por materia**: «Español 45 %».
 * Sin tema por ítem no hay diagnóstico por tema, ni «temas más recomendados»
 * para el docente ([173]), ni banco de ítems que merezca ese nombre (E2).
 *
 * **`topic_normalized` es una columna generada, no un campo que rellene el
 * código.** El tema lo teclea cada docente, así que «Fracciones», «fracciones »
 * y «Fracciones  equivalentes» con dos espacios serían temas distintos para un
 * `GROUP BY`. La normalización vive en la base —minúsculas, sin espacios en los
 * extremos y con los interiores colapsados— para que ninguna vía de escritura
 * pueda saltársela: ni una carga masiva, ni un seeder, ni un INSERT a mano.
 * Se guarda además el texto tal cual lo escribió el docente, que es el que se
 * muestra. No cubre sinónimos ni tildes: para eso haría falta un catálogo de
 * temas por materia, que se valoró y se dejó fuera por coste (tabla, CRUD,
 * pantalla y otro diagrama en el informe).
 *
 * `difficulty` se declara igual que en `study_resources` —varchar con CHECK de
 * tres valores, no enum nativo— a propósito: las dos columnas se comparan entre
 * sí al sugerir un recurso del nivel adecuado, y comparar tipos distintos
 * obligaría a castear en cada consulta.
 *
 * `study_resources.subject_id` es **nullable** y con `ON DELETE SET NULL`: un
 * recurso sin materia sigue sirviendo como material genérico, que es lo que son
 * todos los que ya existen, y borrar una materia no debe llevarse su biblioteca
 * por delante.
 */
return new class extends Migration
{
    /** Los mismos tres niveles que ya usa `study_resources`. */
    private const NIVELES = "'basic', 'intermediate', 'advanced'";

    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->string('topic', 120)->nullable()->after('question_text');
            $table->string('indicator', 255)->nullable()->after('topic');
        });

        DB::statement(
            "ALTER TABLE public.questions
             ADD COLUMN difficulty character varying(255) NULL
             CONSTRAINT questions_difficulty_check
             CHECK (difficulty IS NULL OR difficulty = ANY (ARRAY[" . self::NIVELES . "]))"
        );

        // Columna generada: la normalización no puede depender de quién escriba.
        DB::statement(
            "ALTER TABLE public.questions
             ADD COLUMN topic_normalized character varying(120)
             GENERATED ALWAYS AS (
                 NULLIF(regexp_replace(btrim(lower(topic)), '\\s+', ' ', 'g'), '')
             ) STORED"
        );

        DB::statement('CREATE INDEX questions_topic_normalized_index ON public.questions (topic_normalized)');

        Schema::table('study_resources', function (Blueprint $table) {
            $table->uuid('subject_id')->nullable()->after('institution_id');

            $table->foreign('subject_id')->references('id')->on('subjects')->nullOnDelete();
            $table->index('subject_id', 'study_resources_subject_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('study_resources', function (Blueprint $table) {
            $table->dropForeign(['subject_id']);
            $table->dropIndex('study_resources_subject_id_index');
            $table->dropColumn('subject_id');
        });

        DB::statement('DROP INDEX IF EXISTS public.questions_topic_normalized_index');

        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn(['topic_normalized', 'difficulty', 'indicator', 'topic']);
        });
    }
};
