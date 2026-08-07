<?php

declare(strict_types=1);

use App\Application\Integrations\Contracts\NotionPublicationClient;
use App\Application\Integrations\NotionPublicationException;
use App\Domain\Integrations\IntegrationCredentialSecret;
use App\Infrastructure\Integrations\CacheIntegrationCircuitBreaker;
use App\Infrastructure\Integrations\Notion\CircuitBreakingNotionPublicationClient;
use App\Infrastructure\Integrations\Notion\NotionCircuitScope;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;

beforeEach(function (): void {
    config()->set([
        'app.key' => 'base64:test-application-key',

        'cache.default' => 'array',

        'integration-resilience.circuit.failure_threshold' => 2,
        'integration-resilience.circuit.failure_window_seconds' => 60,
        'integration-resilience.circuit.open_seconds' => 60,
        'integration-resilience.circuit.half_open_lease_seconds' => 15,
        'integration-resilience.circuit.lock_seconds' => 5,
        'integration-resilience.circuit.lock_wait_seconds' => 1,

        'integration-resilience.notion.transient_failure_categories' => [
            'rate_limited',
            'provider_unavailable',
        ],
    ]);

    Cache::flush();
});

afterEach(function (): void {
    Cache::flush();
});

test('it stops calling the raw client after repeated provider failures', function (): void {
    $inner = mock(
        NotionPublicationClient::class,
        function (MockInterface $mock): void {
            $mock->shouldReceive('retrieveDataSource')
                ->twice()
                ->andThrow(
                    new NotionPublicationException(
                        category: 'provider_unavailable',
                        retryable: true,
                    ),
                );
        },
    );

    $client = new CircuitBreakingNotionPublicationClient(
        inner: $inner,
        circuitBreaker: new CacheIntegrationCircuitBreaker,
        scope: new NotionCircuitScope,
    );

    $credential = IntegrationCredentialSecret::from(
        str_repeat('a', 32),
    );

    for ($attempt = 0; $attempt < 2; $attempt++) {
        try {
            $client->retrieveDataSource(
                credential: $credential,
                dataSourceId: 'data-source-id',
            );
        } catch (NotionPublicationException $exception) {
            expect($exception->category)
                ->toBe('provider_unavailable');
        }
    }

    try {
        $client->retrieveDataSource(
            credential: $credential,
            dataSourceId: 'data-source-id',
        );

        $this->fail(
            'The third operation was expected to fail fast.',
        );
    } catch (NotionPublicationException $exception) {
        expect($exception->category)
            ->toBe('circuit_open')
            ->and($exception->retryable)
            ->toBeTrue();
    }
});

test('non transient provider failures do not open the circuit', function (): void {
    $inner = mock(
        NotionPublicationClient::class,
        function (MockInterface $mock): void {
            $mock->shouldReceive('retrieveDataSource')
                ->times(3)
                ->andThrow(
                    new NotionPublicationException(
                        category: 'invalid_token',
                        retryable: false,
                    ),
                );
        },
    );

    $client = new CircuitBreakingNotionPublicationClient(
        inner: $inner,
        circuitBreaker: new CacheIntegrationCircuitBreaker,
        scope: new NotionCircuitScope,
    );

    $credential = IntegrationCredentialSecret::from(
        str_repeat('b', 32),
    );

    for ($attempt = 0; $attempt < 3; $attempt++) {
        try {
            $client->retrieveDataSource(
                credential: $credential,
                dataSourceId: 'data-source-id',
            );
        } catch (NotionPublicationException $exception) {
            expect($exception->category)
                ->toBe('invalid_token');
        }
    }
});
