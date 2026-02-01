<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Traits\UsesPostgresSchema;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication, UsesPostgresSchema;

    protected static bool $schemaLoaded = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! static::$schemaLoaded) {
            $this->refreshPostgresSchema();
            static::$schemaLoaded = true;
        }
    }
}