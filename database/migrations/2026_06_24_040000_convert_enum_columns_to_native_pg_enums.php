<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Convierte las columnas "enum" que la migración original creó como
 * `$table->enum()` (en Postgres = varchar + CHECK) a los tipos ENUM nativos
 * de PostgreSQL definidos en database/sql/01_schema.sql.
 *
 * Motivo (encontrado en auditoría):
 *  - Sin enum nativo, un valor fuera de rango se cuela en un varchar y revienta
 *    al hidratar el modelo (cast a enum PHP → ValueError 500). Igual que el bug
 *    de recommendation_type.
 *  - Peor: algunos CHECK no incluían todos los valores válidos. Ej.:
 *    students.status era enum(['active','inactive']) → `suspended` (válido en la
 *    app) daba 500 por violación de CHECK. El enum nativo student_status incluye
 *    los 3 valores y queda alineado.
 *
 * Las columnas ya convertidas antes (review_status, learning_style,
 * recommendation_type) no se tocan.
 */
return new class extends Migration
{
    /** [tabla, columna, tipo_pg, valores, default|null] */
    private array $conversions = [
        ['users',           'user_type',       'user_type',           ['admin', 'teacher', 'student', 'parent'],                          null],
        ['users',           'status',          'user_status',         ['active', 'inactive', 'suspended'],                                'active'],
        ['exams',           'status',          'exam_status',         ['draft', 'published', 'active', 'completed'],                      'draft'],
        ['questions',       'question_type',   'question_type',       ['multiple_choice', 'true_false', 'short_answer', 'essay'],         null],
        ['study_resources', 'resource_type',   'resource_type',       ['video', 'article', 'exercise', 'book', 'pdf', 'link', 'other'],   null],
        ['students',        'status',          'student_status',      ['active', 'inactive', 'suspended'],                                'active'],
        ['students',        'adecuacion_type', 'adecuacion_type',     ['acceso', 'contenido', 'evaluacion'],                              null],
        ['calendar_events', 'event_type',      'calendar_event_type', ['exam', 'activity', 'reminder', 'meeting'],                        null],
        ['exam_attempts',   'grade_status',    'grade_status',        ['pending', 'graded', 'completed'],                                 'pending'],
    ];

    public function up(): void
    {
        foreach ($this->conversions as [$table, $column, $type, $values, $default]) {
            $valuesList = "'" . implode("','", $values) . "'";

            // 1. Quitar el CHECK heredado de $table->enum() y el default (no se puede
            //    castear un default varchar al nuevo tipo automáticamente).
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_{$column}_check");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} DROP DEFAULT");

            // 2. Crear el tipo enum nativo si no existe.
            DB::statement(
                "DO \$\$ BEGIN
                    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = '{$type}') THEN
                        CREATE TYPE {$type} AS ENUM ({$valuesList});
                    END IF;
                END \$\$;"
            );

            // 3. Convertir la columna al enum nativo (solo si aún es texto).
            DB::statement(
                "DO \$\$ BEGIN
                    IF (
                        SELECT data_type FROM information_schema.columns
                        WHERE table_name = '{$table}' AND column_name = '{$column}'
                    ) <> 'USER-DEFINED' THEN
                        ALTER TABLE {$table}
                            ALTER COLUMN {$column} TYPE {$type}
                            USING {$column}::{$type};
                    END IF;
                END \$\$;"
            );

            // 4. Restaurar el default (ya tipado como enum).
            if ($default !== null) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} SET DEFAULT '{$default}'::{$type}");
            }
        }
    }

    public function down(): void
    {
        foreach ($this->conversions as [$table, $column, $type, $values, $default]) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} DROP DEFAULT");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE varchar(255) USING {$column}::text");
            if ($default !== null) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} SET DEFAULT '{$default}'");
            }
        }
    }
};
