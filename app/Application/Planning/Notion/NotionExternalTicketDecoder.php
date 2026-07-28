<?php

declare(strict_types=1);

namespace App\Application\Planning\Notion;

use App\Models\RoadmapTask;
use InvalidArgumentException;

/** Converts the governed Notion subset into an internal task patch. */
final class NotionExternalTicketDecoder
{
    private const HEADINGS = [
        'Objective', 'Scope', 'Exclusions', 'Acceptance Criteria',
        'Dependency Identifiers', 'Required Evidence', 'Source References',
        'Risks', 'Implementation Notes', 'QA Findings', 'Final Disposition',
    ];

    /**
     * @param  array<string, mixed>  $properties
     * @return array{title:string, objective:string, ticket_type:string, scope:array{included:list<string>, excluded:list<string>}, acceptance_criteria:array<string, string>, evidence_requirements:list<string>, priority:string, risk:string, estimated_complexity:int, human_approval_required:bool, notion_body_overrides:array<string, list<string>>}
     */
    public function decode(RoadmapTask $task, array $properties, string $body): array
    {
        $sections = $this->sections($body);
        $risk = $this->select($properties, 'Risk');
        if ($sections['Risks'] !== [$risk]) {
            throw new InvalidArgumentException('The Notion risk property and body are inconsistent.');
        }
        if ($sections['Dependency Identifiers'] !== $this->dependencies($task)) {
            throw new InvalidArgumentException('External dependency edits require a governed roadmap change.');
        }
        if ($sections['Source References'] !== $this->references($task)) {
            throw new InvalidArgumentException('External source-reference edits require a governed roadmap change.');
        }
        if ($sections['Required Evidence'] !== $this->richText($properties, 'Evidence Requirements')) {
            throw new InvalidArgumentException('The Notion evidence property and body are inconsistent.');
        }

        $existingCriteria = $task->acceptance_criteria;
        if (count($sections['Acceptance Criteria']) !== count($existingCriteria)) {
            throw new InvalidArgumentException('External acceptance-criterion count changes require a governed roadmap change.');
        }
        $criteria = [];
        foreach ($existingCriteria as $index => $criterion) {
            if (! isset($criterion['stable_id']) || ! is_string($criterion['stable_id'])) {
                throw new InvalidArgumentException('The internal acceptance criteria are invalid.');
            }
            $criteria[$criterion['stable_id']] = $sections['Acceptance Criteria'][$index];
        }

        return [
            'title' => $this->title($properties),
            'objective' => $this->sole($sections['Objective'], 'Objective'),
            'ticket_type' => $this->select($properties, 'Type'),
            'scope' => ['included' => $sections['Scope'], 'excluded' => $sections['Exclusions']],
            'acceptance_criteria' => $criteria,
            'evidence_requirements' => $sections['Required Evidence'],
            'priority' => $this->select($properties, 'Priority'),
            'risk' => $risk,
            'estimated_complexity' => $this->complexity($properties),
            'human_approval_required' => $this->checkbox($properties),
            'notion_body_overrides' => [
                'Implementation Notes' => $sections['Implementation Notes'],
                'QA Findings' => $sections['QA Findings'],
                'Final Disposition' => $sections['Final Disposition'],
            ],
        ];
    }

    /** @return array<string, list<string>> */
    private function sections(string $body): array
    {
        $parts = preg_split('/^## (.+)$/m', trim(str_replace("\r\n", "\n", $body)), -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false || $parts === [] || trim((string) array_shift($parts)) !== '' || count($parts) !== count(self::HEADINGS) * 2) {
            throw new InvalidArgumentException('The Notion ticket body is not in the governed canonical format.');
        }
        $sections = [];
        while ($parts !== []) {
            $heading = array_shift($parts);
            $content = array_shift($parts);
            if (! is_string($content) || ! in_array($heading, self::HEADINGS, true) || isset($sections[$heading])) {
                throw new InvalidArgumentException('The Notion ticket body contains invalid sections.');
            }
            $lines = array_values(array_filter(explode("\n", trim($content)), static fn (string $line): bool => trim($line) !== ''));
            $sections[$heading] = $lines === ['- None.'] ? [] : array_map(function (string $line): string {
                if (! str_starts_with($line, '- ') || trim(substr($line, 2)) === '') {
                    throw new InvalidArgumentException('The Notion ticket body contains invalid section content.');
                }

                return trim(substr($line, 2));
            }, $lines);
        }
        if (count($sections) !== count(self::HEADINGS)) {
            throw new InvalidArgumentException('The Notion ticket body section order is invalid.');
        }

        return $sections;
    }

    /** @param array<string, mixed> $properties */
    private function title(array $properties): string
    {
        $value = $this->text($properties['Name']['title'] ?? null);
        if ($value === '') {
            throw new InvalidArgumentException('The Notion ticket name is required.');
        }

        return $value;
    }

    /** @param array<string, mixed> $properties */
    private function select(array $properties, string $name): string
    {
        $value = $properties[$name]['select']['name'] ?? null;
        if ($name === 'Status') {
            $value = $properties[$name]['status']['name'] ?? null;
        }
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("The Notion {$name} property is invalid.");
        }

        return $value;
    }

    /** @param array<string, mixed> $properties */
    private function complexity(array $properties): int
    {
        $value = $properties['Complexity']['number'] ?? null;
        if (! is_int($value) || $value < 1 || $value > 13) {
            throw new InvalidArgumentException('The Notion complexity property is invalid.');
        }

        return $value;
    }

    /** @param array<string, mixed> $properties */
    private function checkbox(array $properties): bool
    {
        $value = $properties['Requires Approval']['checkbox'] ?? null;
        if (! is_bool($value)) {
            throw new InvalidArgumentException('The Notion approval property is invalid.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return list<string>
     */
    private function richText(array $properties, string $name): array
    {
        $text = $this->text($properties[$name]['rich_text'] ?? null);

        return $text === '' ? [] : explode("\n", $text);
    }

    private function text(mixed $fragments): string
    {
        if (! is_array($fragments)) {
            return '';
        }

        return collect($fragments)->map(fn (mixed $fragment): string => is_array($fragment) ? (string) ($fragment['plain_text'] ?? $fragment['text']['content'] ?? '') : '')->implode('');
    }

    /** @return list<string> */
    private function dependencies(RoadmapTask $task): array
    {
        if (! $task->relationLoaded('dependencies')) {
            $task->load('dependencies.dependsOn');
        }

        return array_values($task->dependencies->map(fn ($dependency): string => $dependency->dependsOn->stable_id)->sort()->values()->all());
    }

    /** @return list<string> */
    private function references(RoadmapTask $task): array
    {
        if (! $task->relationLoaded('traceabilityLinks')) {
            $task->load('traceabilityLinks.documentVersion.document');
        }

        return array_values($task->traceabilityLinks->map(fn ($link): string => $link->criterion_stable_id.': '.$link->documentVersion->document->title.' v'.$link->document_version)->sort()->values()->all());
    }

    /** @param list<string> $items */
    private function sole(array $items, string $section): string
    {
        if (count($items) !== 1 || $items[0] === '') {
            throw new InvalidArgumentException("The Notion {$section} section must contain exactly one item.");
        }

        return $items[0];
    }
}
