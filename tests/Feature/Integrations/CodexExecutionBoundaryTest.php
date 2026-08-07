<?php

declare(strict_types=1);

use App\Application\Planning\ExecutionProviderRegistry;
use App\Application\QualityAssurance\QualityAssuranceProviderRegistry;
use App\Domain\Projects\Configuration\CodexProviderPolicy;
use App\Domain\Projects\Configuration\ProviderPolicy;

it('does not register codex as a real planning execution provider', function (): void {
    $codex = CodexProviderPolicy::defaults();
    $codex['enabled'] = true;

    $policy = ProviderPolicy::fromArray([
        'allowed_provider_ids' => ['codex'],
        'fallback_order' => ['codex'],
        'codex' => $codex,
    ]);

    $registry = app(ExecutionProviderRegistry::class);

    expect(fn () => $registry->resolve(
        policy: $policy,
        capability: 'planning.generate',
    ))->toThrow(
        LogicException::class,
        'No allowed provider supports capability [planning.generate].',
    );
});

it('does not register codex as a real qa execution provider', function (): void {
    $registry = app(QualityAssuranceProviderRegistry::class);

    expect(fn () => $registry->resolve(
        fallbackOrder: ['codex'],
        capability: 'quality_assurance.review',
    ))->toThrow(
        LogicException::class,
        'No permitted provider supports [quality_assurance.review].',
    );
});
