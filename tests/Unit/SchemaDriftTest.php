<?php

namespace Tests\Unit;

use App\Support\SchemaDrift;
use PHPUnit\Framework\TestCase;

/**
 * La comparación de `schema:check-drift`. El comando entero necesita crear una
 * base y lanzar `pg_dump`, así que aquí se prueba solo lo que decide si hay
 * drift: qué se ignora y qué no.
 */
class SchemaDriftTest extends TestCase
{
    private const VOLCADO = "--\n-- Dumped from database version 17.6\n-- Dumped by pg_dump version 17.9\n\n"
        . "CREATE TABLE public.students (\n    student_code character varying(255)\n);\n";

    public function test_identical_dumps_have_no_drift(): void
    {
        $this->assertNull(SchemaDrift::diff(self::VOLCADO, self::VOLCADO));
    }

    public function test_pg_dump_version_headers_are_ignored(): void
    {
        // El artefacto sale de Supabase y la comparación de una BD local:
        // estas dos líneas difieren siempre sin que el esquema cambie.
        $local = str_replace(['version 17.6', 'version 17.9'], ['version 16.2', 'version 16.4'], self::VOLCADO);

        $this->assertNull(SchemaDrift::diff(self::VOLCADO, $local));
    }

    public function test_line_endings_are_ignored(): void
    {
        $this->assertNull(SchemaDrift::diff(str_replace("\n", "\r\n", self::VOLCADO), self::VOLCADO));
    }

    public function test_a_column_change_is_drift(): void
    {
        $migrado = str_replace('varying(255)', 'varying(100)', self::VOLCADO);

        $diff = SchemaDrift::diff(self::VOLCADO, $migrado);

        $this->assertNotNull($diff);
        $this->assertStringContainsString('-    student_code character varying(255)', $diff);
        $this->assertStringContainsString('+    student_code character varying(100)', $diff);
    }

    public function test_other_comment_lines_still_count(): void
    {
        // Solo se ignoran las cabeceras de versión, no cualquier comentario.
        $migrado = str_replace("--\n", "-- Name: students; Type: TABLE\n", self::VOLCADO);

        $this->assertNotNull(SchemaDrift::diff(self::VOLCADO, $migrado));
    }
}
