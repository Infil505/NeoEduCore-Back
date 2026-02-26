<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_ping_endpoint(): void
    {
        $res = $this->getJson('/api/ping');

        $res->assertOk();
        $this->assertTrue($res->json('ok'));
    }
}
