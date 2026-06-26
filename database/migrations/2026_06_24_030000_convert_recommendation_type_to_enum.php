<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Convierte ai_recommendations.recommendation_type de varchar al enum nativo
 * de PostgreSQL `ai_recommendation_type` ('strength','weakness','resource','action').
 *
 * Motivo: la migración original creó la columna como `string`, lo que permitió
 * insertar valores fuera del enum (el seeder usaba 'study_plan'/'support_resource').
 * Esos valores hacían 500 al hidratar el modelo (cast a enum PHP). Con el enum
 * nativo, un valor inválido falla en el INSERT (ruidoso) en lugar de al leer.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Crear el tipo si no existe (igual que en 01_schema.sql)
        DB::statement(<<<'SQL'
            DO $$ BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'ai_recommendation_type') THEN
                    CREATE TYPE ai_recommendation_type AS ENUM ('strength','weakness','resource','action');
                END IF;
            END $$;
        SQL);

        // 2. Coercer valores heredados inválidos antes de convertir
        DB::table('ai_recommendations')->where('recommendation_type', 'study_plan')->update(['recommendation_type' => 'action']);
        DB::table('ai_recommendations')->where('recommendation_type', 'support_resource')->update(['recommendation_type' => 'resource']);
        DB::table('ai_recommendations')
            ->whereNotIn('recommendation_type', ['strength', 'weakness', 'resource', 'action'])
            ->update(['recommendation_type' => 'action']);

        // 3. Convertir la columna a enum nativo solo si aún es texto
        DB::statement(<<<'SQL'
            DO $$ BEGIN
                IF (
                    SELECT data_type FROM information_schema.columns
                    WHERE table_name = 'ai_recommendations' AND column_name = 'recommendation_type'
                ) <> 'USER-DEFINED' THEN
                    ALTER TABLE ai_recommendations
                        ALTER COLUMN recommendation_type TYPE ai_recommendation_type
                        USING recommendation_type::ai_recommendation_type;
                END IF;
            END $$;
        SQL);
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE ai_recommendations
             ALTER COLUMN recommendation_type TYPE varchar(255)
             USING recommendation_type::text"
        );
    }
};
