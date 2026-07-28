<?php

declare(strict_types=1);

use App\Application\Planning\Notion\NotionExternalTicketDecoder;
use App\Models\RoadmapTask;
use Illuminate\Support\Collection;

function externalNotionTask(): RoadmapTask
{
    $task = new RoadmapTask;
    $task->acceptance_criteria = [['stable_id' => 'criterion-one', 'description' => 'Original criterion']];
    $task->setRelation('dependencies', new Collection);
    $task->setRelation('traceabilityLinks', new Collection);

    return $task;
}

/** @return array<string, mixed> */
function externalNotionProperties(): array
{
    return [
        'Name' => ['title' => [['plain_text' => 'Accepted external title']]],
        'Type' => ['select' => ['name' => 'feature']],
        'Priority' => ['select' => ['name' => 'high']],
        'Risk' => ['select' => ['name' => 'medium']],
        'Complexity' => ['number' => 5],
        'Requires Approval' => ['checkbox' => true],
        'Evidence Requirements' => ['rich_text' => [['plain_text' => 'Evidence one']]],
    ];
}

function externalNotionBody(string $dependencies = ''): string
{
    return implode("\n\n", [
        "## Objective\n- Accepted objective",
        "## Scope\n- Included scope",
        "## Exclusions\n- Excluded scope",
        "## Acceptance Criteria\n- Accepted criterion",
        "## Dependency Identifiers\n".($dependencies === '' ? '- None.' : '- '.$dependencies),
        "## Required Evidence\n- Evidence one",
        "## Source References\n- None.",
        "## Risks\n- medium",
        "## Implementation Notes\n- Keep this implementation note",
        "## QA Findings\n- QA evidence",
        "## Final Disposition\n- Ready for approval",
    ]);
}

test('decodes the governed Notion subset without losing external-only body sections', function (): void {
    $patch = app(NotionExternalTicketDecoder::class)->decode(externalNotionTask(), externalNotionProperties(), externalNotionBody());

    expect($patch)->toMatchArray([
        'title' => 'Accepted external title',
        'objective' => 'Accepted objective',
        'ticket_type' => 'feature',
        'scope' => ['included' => ['Included scope'], 'excluded' => ['Excluded scope']],
        'acceptance_criteria' => ['criterion-one' => 'Accepted criterion'],
        'evidence_requirements' => ['Evidence one'],
        'priority' => 'high',
        'risk' => 'medium',
        'estimated_complexity' => 5,
        'human_approval_required' => true,
        'notion_body_overrides' => ['Implementation Notes' => ['Keep this implementation note'], 'QA Findings' => ['QA evidence'], 'Final Disposition' => ['Ready for approval']],
    ]);
});

test('rejects external dependency changes because the internal graph remains governed', function (): void {
    app(NotionExternalTicketDecoder::class)->decode(externalNotionTask(), externalNotionProperties(), externalNotionBody('other-task'));
})->throws(InvalidArgumentException::class, 'dependency edits require a governed roadmap change');
