<?php

declare(strict_types=1);

use App\Application\Integrations\Data\NotionDataSource;
use App\Application\Planning\Notion\NotionTicketSchema;

test('it reports every missing or incompatible required property', function (): void {
    $source = new NotionDataSource(
        id: 'source-1',
        name: 'Tickets',
        properties: [
            'Ticket ID' => ['type' => 'title'],
            'Name' => ['type' => 'title'],
            'Status' => ['type' => 'status'],
        ],
        providerRequestId: 'req-1',
    );

    $diagnostics = app(NotionTicketSchema::class)->readinessDiagnostics($source);

    expect($diagnostics)->toHaveCount(9)
        ->and($diagnostics)->toContain([
            'property' => 'Ticket ID',
            'expected' => 'rich_text',
            'actual' => 'title',
        ]);
});

test('it accepts the versioned canonical ticket schema', function (): void {
    $properties = [];
    foreach ([
        'Ticket ID' => 'rich_text', 'Name' => 'title', 'Status' => 'status',
        'Type' => 'select', 'Priority' => 'select', 'Risk' => 'select',
        'Complexity' => 'number', 'Requires Approval' => 'checkbox',
        'Dependencies' => 'rich_text', 'Evidence Requirements' => 'rich_text',
        'Source References' => 'rich_text',
    ] as $name => $type) {
        $properties[$name] = ['type' => $type];
    }

    $source = new NotionDataSource('source-1', 'Tickets', $properties, null);

    expect(app(NotionTicketSchema::class)->isReady($source))->toBeTrue();
});

test('example', function () {
    expect(true)->toBeTrue();
});
