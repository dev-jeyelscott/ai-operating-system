<?php

declare(strict_types=1);

namespace App\Application\Planning\Notion;

use App\Models\ExternalTicketMapping;
use App\Models\RoadmapTask;

/** Produces deterministic Notion properties, body, and synchronization hash. */
final class NotionTicketMapper
{
    /** @return array{properties:array<string, mixed>, body:string, fingerprint:string} */
    public function map(RoadmapTask $task, ExternalTicketMapping $mapping): array
    {
        $task->loadMissing(['roadmap', 'phase', 'milestone', 'dependencies.dependsOn', 'traceabilityLinks.documentVersion.document']);
        $dependencies = array_values($task->dependencies
            ->map(fn ($dependency): string => $dependency->dependsOn->stable_id)
            ->sort()->values()->all());
        $references = array_values($task->traceabilityLinks
            ->map(fn ($link): string => $link->criterion_stable_id.': '.$link->documentVersion->document->title.' v'.$link->document_version)
            ->sort()->values()->all());
        $body = $this->body($task, $dependencies, $references);
        $properties = [
            'Ticket ID' => $this->richText($mapping->external_key),
            'Name' => ['title' => [['type' => 'text', 'text' => ['content' => $task->title]]]],
            'Status' => ['status' => ['name' => 'In Progress']],
            'Type' => ['select' => ['name' => $task->ticket_type]],
            'Priority' => ['select' => ['name' => $task->priority]],
            'Risk' => ['select' => ['name' => $task->risk]],
            'Complexity' => ['number' => $task->estimated_complexity],
            'Requires Approval' => ['checkbox' => $task->human_approval_required],
            'Dependencies' => $this->richText(implode(', ', $dependencies)),
            'Evidence Requirements' => $this->richText(implode("\n", $this->sortedStrings($task->evidence_requirements))),
            'Source References' => $this->richText(implode("\n", $references)),
        ];
        $canonical = ['schema_version' => NotionTicketSchema::VERSION, 'properties' => $properties, 'body' => $body];

        return ['properties' => $properties, 'body' => $body, 'fingerprint' => hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))];
    }

    /**
     * @param  list<string>  $dependencies
     * @param  list<string>  $references
     */
    private function body(RoadmapTask $task, array $dependencies, array $references): string
    {
        $scope = $task->scope;
        $included = $this->sortedStrings($scope['included']);
        $excluded = $this->sortedStrings($scope['excluded']);
        $acceptance = collect($task->acceptance_criteria)->map(fn (array $criterion): string => (string) ($criterion['description'] ?? ''))->filter()->sort()->values()->all();
        $overrides = $task->notion_body_overrides ?? [];
        $sections = [
            'Objective' => [$task->objective],
            'Scope' => $included,
            'Exclusions' => $excluded,
            'Acceptance Criteria' => $acceptance,
            'Dependency Identifiers' => $dependencies,
            'Required Evidence' => $this->sortedStrings($task->evidence_requirements),
            'Source References' => $references,
            'Risks' => [$task->risk],
            'Implementation Notes' => $this->override($overrides, 'Implementation Notes', ['Publish from approved roadmap revision '.$task->roadmap->revision.'.']),
            'QA Findings' => $this->override($overrides, 'QA Findings', []),
            'Final Disposition' => $this->override($overrides, 'Final Disposition', ['Staged for implementation.']),
        ];

        return collect($sections)->map(function (array $items, string $heading): string {
            $content = $items === [] ? '- None.' : collect($items)->map(fn (string $item): string => '- '.$item)->implode("\n");

            return '## '.$heading."\n".$content;
        })->implode("\n\n");
    }

    /** @return list<string> */
    private function sortedStrings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }
        $values = array_values(array_filter($values, 'is_string'));
        sort($values, SORT_STRING);

        return $values;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  list<string>  $default
     * @return list<string>
     */
    private function override(array $overrides, string $section, array $default): array
    {
        if (! array_key_exists($section, $overrides)) {
            return $default;
        }

        return $this->sortedStrings($overrides[$section]);
    }

    /** @return array{rich_text:list<array<string, mixed>>} */
    private function richText(string $content): array
    {
        return ['rich_text' => $content === '' ? [] : [['type' => 'text', 'text' => ['content' => $content]]]];
    }
}
