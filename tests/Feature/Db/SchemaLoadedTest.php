<?php

namespace Tests\Feature\Db;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;

class SchemaLoadedTest extends TestCase
{
    public function test_schema_tables_exist(): void
    {
        $tables = DB::select("
            SELECT tablename
            FROM pg_tables
            WHERE schemaname = 'public'
        ");

        $names = array_map(fn($t) => $t->tablename, $tables);

        $this->assertContains('institutions', $names);
        $this->assertContains('users', $names);
        $this->assertContains('subjects', $names);
        $this->assertContains('exams', $names);
    }
}