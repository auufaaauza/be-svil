<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiHealthTest extends TestCase
{
    public function test_backend_exposes_json_service_identity_and_health_endpoint(): void
    {
        $this->getJson('/')->assertOk()->assertJson(['name' => 'SVIL API', 'version' => 'v1', 'status' => 'ok']);
        $this->get('/up')->assertOk();
    }
}
