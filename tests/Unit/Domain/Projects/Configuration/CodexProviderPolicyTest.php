<?php

declare(strict_types=1);

use App\Domain\Projects\Configuration\CodexProviderPolicy;
use App\Domain\Projects\Configuration\ReasoningLevel;

it('defaults codex to disabled with deny by default network and bounded sandbox profiles', function (): void {
    $policy = CodexProviderPolicy::fromArray(
        CodexProviderPolicy::defaults(),
    );

    expect($policy->enabled)->toBeFalse()
        ->and($policy->network['default'])->toBe('deny')
        ->and($policy->sandbox['planning'])->toBe('read-only')
        ->and($policy->sandbox['quality_assurance'])->toBe('read-only')
        ->and($policy->modelIdentifier)->toBe('gpt-5.3-codex');
});

it('rejects an enabled codex policy whose project reasoning is outside its limits', function (): void {
    $payload = CodexProviderPolicy::defaults();
    $payload['enabled'] = true;
    $payload['reasoning'] = [
        'minimum' => 'high',
        'maximum' => 'high',
    ];

    $policy = CodexProviderPolicy::fromArray($payload);

    $policy->assertWithinProjectPolicy(
        projectDefaultReasoning: ReasoningLevel::Medium,
        projectBudgetLimitMinor: 10_000,
        projectAutomaticRetryLimit: 3,
    );
})->throws(
    InvalidArgumentException::class,
    'Project default reasoning must fall within the configured Codex reasoning limits.',
);

it('rejects codex network allow by default', function (): void {
    $payload = CodexProviderPolicy::defaults();
    $payload['network']['default'] = 'allow';

    CodexProviderPolicy::fromArray($payload);
})->throws(
    InvalidArgumentException::class,
    'Codex network policy must remain deny by default.',
);

it('rejects a development sandbox broader than workspace write', function (): void {
    $payload = CodexProviderPolicy::defaults();
    $payload['sandbox']['development'] = 'danger-full-access';

    CodexProviderPolicy::fromArray($payload);
})->throws(InvalidArgumentException::class);
