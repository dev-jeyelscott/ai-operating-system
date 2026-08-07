<?php

declare(strict_types=1);

namespace App\Application\Planning\Notion;

use App\Application\Integrations\Data\NotionDataSource;

/** Versioned contract for the project-owned Notion ticket data source. */
final class NotionTicketSchema
{
    public const VERSION = 1;

    /** @var array<string, string> */
    private const REQUIRED_PROPERTIES = [
        'Ticket ID' => 'rich_text',
        'Name' => 'title',
        'Status' => 'status',
        'Type' => 'select',
        'Priority' => 'select',
        'Risk' => 'select',
        'Complexity' => 'number',
        'Requires Approval' => 'checkbox',
        'Dependencies' => 'rich_text',
        'Evidence Requirements' => 'rich_text',
        'Source References' => 'rich_text',
    ];

    /** @return list<array{property:string, expected:string, actual:string|null}> */
    public function readinessDiagnostics(NotionDataSource $dataSource): array
    {
        return $this->readinessDiagnosticsForProperties($dataSource->properties);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return list<array{property:string, expected:string, actual:string|null}>
     */
    public function readinessDiagnosticsForProperties(array $properties): array
    {
        $diagnostics = [];
        foreach (self::REQUIRED_PROPERTIES as $name => $expectedType) {
            $property = $properties[$name] ?? null;
            $actualType = is_array($property) && is_string($property['type'] ?? null)
                ? $property['type']
                : null;
            if ($actualType !== $expectedType) {
                $diagnostics[] = [
                    'property' => $name,
                    'expected' => $expectedType,
                    'actual' => $actualType,
                ];
            }
        }

        return $diagnostics;
    }

    public function isReady(NotionDataSource $dataSource): bool
    {
        return $this->readinessDiagnostics($dataSource) === [];
    }

    /** @param array<string, mixed> $properties */
    public function propertiesAreReady(array $properties): bool
    {
        return $this->readinessDiagnosticsForProperties($properties) === [];
    }
}
