<?php

namespace Tests\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

trait UsesPostgresSchema
{
    protected function refreshPostgresSchema(): void
    {
        // Importante: para que no quede data vieja entre tests/clases,
        // lo hacemos una vez por clase usando setUpBeforeClass.
        $path = base_path('database/sql/01_schema.sql');

        if (! File::exists($path)) {
            $this->fail("Schema file not found: {$path}");
        }

        $sql = File::get($path);

        // Limpia el schema public y lo recrea
        DB::statement('DROP SCHEMA public CASCADE;');
        DB::statement('CREATE SCHEMA public;');

        // Algunas instalaciones requieren reasignar permisos
        DB::statement('GRANT ALL ON SCHEMA public TO public;');

        // Ejecuta tu schema completo
        DB::unprepared($sql);
    }
}