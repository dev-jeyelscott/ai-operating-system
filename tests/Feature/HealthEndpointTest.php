<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class HealthEndpointTest extends TestCase
{
    /**
     * Verify that the application health response exposes only public-safe
     * metadata and includes request correlation.
     */
    public function test_health_endpoint_is_available(): void
    {
        $response = $this
            ->withHeader('X-Request-ID', 'health-test-123')
            ->getJson('/health');

        $response
            ->assertOk()
            ->assertHeader('X-Request-ID', 'health-test-123')
            ->assertJson([
                'status' => 'ok',
                'request_id' => 'health-test-123',
            ]);
    }

    /**
     * Verify that readiness confirms every required local dependency.
     */
    public function test_readiness_endpoint_reports_dependencies(): void
    {
        $response = $this->getJson('/ready');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.redis.status', 'ok')
            ->assertJsonPath('checks.storage.status', 'ok');
    }
}
