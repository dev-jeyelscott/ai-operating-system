<?php

declare(strict_types=1);

use App\Application\Projects\ProjectConfigurationSnapshotUpcaster;
use App\Domain\Projects\Configuration\ProjectConfigurationSchema;

test('version one snapshots are upcast without inventing credential provenance', function (): void {
    $legacy = [
        'schema_version' => 1,
        'revision' => 4,

        'integrations' => [
            'notion' => [
                'workspace_id' => '17ab3186-873d-418f-b899-c3f6a43f68de',

                'workspace_name' => 'Legacy Workspace',

                'database_id' => 'd9824bdc-8445-4327-be8b-5b47500af6ce',

                'database_name' => 'Legacy Tickets',

                'data_source_id' => '248104cd-477e-80af-bc30-000bd28de8f9',

                'data_source_name' => 'Tickets',
            ],
        ],
    ];

    $current = app(
        ProjectConfigurationSnapshotUpcaster::class,
    )->toCurrent($legacy);

    expect($current['schema_version'])
        ->toBe(ProjectConfigurationSchema::VERSION_2)
        ->and(data_get(
            $current,
            'integrations.notion.connection_status',
        ))
        ->toBe('connected')
        ->and(data_get(
            $current,
            'integrations.notion.database.id',
        ))
        ->toBe(
            'd9824bdc-8445-4327-be8b-5b47500af6ce',
        )
        ->and(data_get(
            $current,
            'integrations.notion.credential.configured',
        ))
        ->toBeNull()
        ->and(data_get(
            $current,
            'integrations.notion.credential.verified_version',
        ))
        ->toBeNull()
        ->and(data_get(
            $current,
            'integrations.notion.verified_at',
        ))
        ->toBeNull();
});
