<?php

declare(strict_types=1);

use App\Application\Simulation\DeterministicScenarioCatalog;

it('returns all 14 scenarios in stable catalog order', function (): void {
    $catalog = new DeterministicScenarioCatalog;

    expect($catalog->all())->toHaveCount(14)
        ->and($catalog->describeAll(seed: 42))->toHaveCount(14);
});

it('returns the same selection and fingerprint for the same seed', function (): void {
    $catalog = new DeterministicScenarioCatalog;

    $first = $catalog->select('happy_path', 42);
    $replay = $catalog->select('happy_path', 42);

    expect($replay)->toBe($first)
        ->and($first['fingerprint'])->toMatch('/\A[0-9a-f]{64}\z/');
});

it('changes the fingerprint when the deterministic seed changes', function (): void {
    $catalog = new DeterministicScenarioCatalog;

    expect($catalog->select('happy_path', 42)['fingerprint'])
        ->not->toBe($catalog->select('happy_path', 43)['fingerprint']);
});

it('rejects unsupported scenario slugs', function (): void {
    $catalog = new DeterministicScenarioCatalog;

    expect(fn (): array => $catalog->select('unknown_scenario', 1))
        ->toThrow(
            InvalidArgumentException::class,
            'Unsupported deterministic scenario',
        );
});

it('rejects negative deterministic seeds', function (): void {
    $catalog = new DeterministicScenarioCatalog;

    expect(fn (): array => $catalog->select('happy_path', -1))
        ->toThrow(
            InvalidArgumentException::class,
            'seed must be zero or greater',
        );
});
