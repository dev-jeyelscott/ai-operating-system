<?php

declare(strict_types=1);

use App\Application\Projects\ProjectConfigurationSnapshotUpcaster;
use App\Domain\Projects\Configuration\ProjectConfigurationSchema;

it('upcasts version two snapshots with codex disabled and without credentials', function (): void {
    $snapshot = [
        'schema_version' => ProjectConfigurationSchema::VERSION_2,
        'revision' => 7,
        'policy' => [
            'provider' => [
                'allowed_provider_ids' => ['simulation'],
                'fallback_order' => ['simulation'],
            ],
        ],
        'integrations' => [
            'notion' => [
                'provider' => 'notion',
            ],
        ],
    ];

    $upcast = app(ProjectConfigurationSnapshotUpcaster::class)
        ->toCurrent($snapshot);

    expect($upcast['schema_version'])
        ->toBe(ProjectConfigurationSchema::VERSION_3)
        ->and($upcast['policy']['provider']['codex']['enabled'])
        ->toBeFalse()
        ->and(json_encode($upcast, JSON_THROW_ON_ERROR))
        ->not->toContain('secret_ciphertext')
        ->not->toContain('credential_confirmation');
});
