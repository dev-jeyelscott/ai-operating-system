<?php

declare(strict_types=1);

use App\Application\Integrations\Exceptions\IntegrationCircuitOpen;
use App\Infrastructure\Integrations\CacheIntegrationCircuitBreaker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    config()->set([
        'cache.default' => 'array',

        'integration-resilience.circuit.failure_threshold' => 3,
        'integration-resilience.circuit.failure_window_seconds' => 60,
        'integration-resilience.circuit.open_seconds' => 60,
        'integration-resilience.circuit.half_open_lease_seconds' => 15,
        'integration-resilience.circuit.lock_seconds' => 5,
        'integration-resilience.circuit.lock_wait_seconds' => 1,
    ]);

    Cache::flush();

    CarbonImmutable::setTestNow(
        CarbonImmutable::parse('2026-08-01 12:00:00'),
    );
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Cache::flush();
});

test('it opens after the configured consecutive failure threshold', function (): void {
    $breaker = new CacheIntegrationCircuitBreaker;

    $breaker->recordFailure('notion', 'credential-write');
    $breaker->recordFailure('notion', 'credential-write');

    /*
     * The circuit remains closed before the configured threshold.
     */
    $breaker->assertCanAttempt('notion', 'credential-write');

    $breaker->recordFailure('notion', 'credential-write');

    try {
        $breaker->assertCanAttempt(
            'notion',
            'credential-write',
        );

        $this->fail(
            'The circuit was expected to reject the provider operation.',
        );
    } catch (IntegrationCircuitOpen $exception) {
        expect($exception->provider)
            ->toBe('notion')
            ->and($exception->retryAfterSeconds)
            ->toBe(60);
    }
});

test('it admits only one half open recovery probe', function (): void {
    $breaker = new CacheIntegrationCircuitBreaker;

    $breaker->recordFailure('notion', 'credential-write');
    $breaker->recordFailure('notion', 'credential-write');
    $breaker->recordFailure('notion', 'credential-write');

    CarbonImmutable::setTestNow(
        CarbonImmutable::parse('2026-08-01 12:01:01'),
    );

    /*
     * The first request after the open duration reserves the probe.
     */
    $breaker->assertCanAttempt(
        'notion',
        'credential-write',
    );

    expect(
        fn () => $breaker->assertCanAttempt(
            'notion',
            'credential-write',
        ),
    )->toThrow(IntegrationCircuitOpen::class);

    $breaker->recordSuccess(
        'notion',
        'credential-write',
    );

    /*
     * A successful recovery probe closes the circuit.
     */
    $breaker->assertCanAttempt(
        'notion',
        'credential-write',
    );
});

test('a failed half open probe reopens the circuit', function (): void {
    $breaker = new CacheIntegrationCircuitBreaker;

    $breaker->recordFailure('notion', 'credential-read');
    $breaker->recordFailure('notion', 'credential-read');
    $breaker->recordFailure('notion', 'credential-read');

    CarbonImmutable::setTestNow(
        CarbonImmutable::parse('2026-08-01 12:01:01'),
    );

    $breaker->assertCanAttempt(
        'notion',
        'credential-read',
    );

    $breaker->recordFailure(
        'notion',
        'credential-read',
    );

    expect(
        fn () => $breaker->assertCanAttempt(
            'notion',
            'credential-read',
        ),
    )->toThrow(IntegrationCircuitOpen::class);
});
