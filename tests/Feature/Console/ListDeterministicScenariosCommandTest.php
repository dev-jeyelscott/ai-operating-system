<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('lists the complete deterministic scenario catalog as JSON', function (): void {
    $exitCode = Artisan::call('simulation:scenarios', [
        '--seed' => 42,
        '--json' => true,
    ]);

    $payload = json_decode(
        trim(Artisan::output()),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($exitCode)->toBe(0)
        ->and($payload['schema_version'])->toBe(1)
        ->and($payload['scenario_count'])->toBe(14)
        ->and($payload['seed'])->toBe(42)
        ->and($payload['scenarios'])->toHaveCount(14);
});

it('describes one seed-specific scenario as JSON', function (): void {
    $exitCode = Artisan::call('simulation:scenarios', [
        'scenario' => 'wrong_pr_target',
        '--seed' => 151,
        '--json' => true,
    ]);

    $payload = json_decode(
        trim(Artisan::output()),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($exitCode)->toBe(0)
        ->and($payload['scenario'])->toBe('wrong_pr_target')
        ->and($payload['seed'])->toBe(151)
        ->and($payload['provider_scenarios']['development'])
        ->toBe('wrong_pr_target')
        ->and($payload['fingerprint'])
        ->toMatch('/\A[0-9a-f]{64}\z/');
});

it('rejects an unsupported scenario', function (): void {
    $exitCode = Artisan::call('simulation:scenarios', [
        'scenario' => 'not-a-scenario',
    ]);

    expect($exitCode)->toBe(2)
        ->and(Artisan::output())
        ->toContain('Unsupported deterministic scenario');
});
