<?php

namespace Tests\Feature\Db;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Invariantes estructurales del esquema.
 *
 * Existen porque `database/sql/01_schema.sql` y las migraciones ya divergieron
 * una vez en silencio (tokenable_id era uuid en la BD real y bigint en las
 * migraciones). Estos tests fallan si el artefacto pierde una de estas
 * garantías; el contraste artefacto vs. migraciones se hace además con un
 * `migrate:fresh` sobre una BD limpia.
 */
class SchemaIntegrityTest extends TestCase
{
    public function test_uuid_columns_keep_their_type(): void
    {
        // Sanctum resuelve el tokenable contra users.id, que es uuid.
        // Si esto vuelve a ser bigint, toda la autenticación se cae.
        $this->assertSame('uuid', $this->tipoDeColumna('personal_access_tokens', 'tokenable_id'));
        $this->assertSame('uuid', $this->tipoDeColumna('users', 'id'));
        $this->assertSame('uuid', $this->tipoDeColumna('institutions', 'id'));
    }

    public function test_subject_name_is_unique_per_institution(): void
    {
        $indexdef = DB::selectOne("
            SELECT indexdef
            FROM pg_indexes
            WHERE schemaname = current_schema()
              AND indexname = 'subjects_institution_name_unique'
        ");

        $this->assertNotNull(
            $indexdef,
            'Falta el índice UNIQUE de nombre de materia por institución.'
        );

        // Debe ser funcional (lower/btrim): si no, "Matemática" y "MATEMÁTICA"
        // pasarían como materias distintas.
        $this->assertStringContainsString('UNIQUE', $indexdef->indexdef);
        $this->assertStringContainsString('lower', $indexdef->indexdef);
        $this->assertStringContainsString('btrim', $indexdef->indexdef);
    }

    private function tipoDeColumna(string $tabla, string $columna): ?string
    {
        $col = DB::selectOne("
            SELECT data_type
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = ?
              AND column_name = ?
        ", [$tabla, $columna]);

        return $col?->data_type;
    }
}
