<?php

declare(strict_types=1);

use App\Application\Planning\Data\PlanningAcceptanceCriterion;
use App\Application\Planning\Data\PlanningDependency;
use App\Application\Planning\Data\PlanningDiagnostic;
use App\Application\Planning\Data\PlanningDocument;
use App\Application\Planning\Data\PlanningExecutionRequest;
use App\Application\Planning\Data\PlanningExecutionResult;
use App\Application\Planning\Data\PlanningMilestone;
use App\Application\Planning\Data\PlanningPhase;
use App\Application\Planning\Data\PlanningRoadmapDefinition;
use App\Application\Planning\Data\PlanningSourceReference;
use App\Application\Planning\Data\PlanningTask;
use App\Application\Planning\ExecutionProviderRegistry;
use App\Application\Planning\MaterializeRoadmap;
use App\Application\Planning\PlanningResultValidator;
use App\Application\Planning\RoadmapGraph;
use App\Application\Planning\RoadmapReadinessEvaluator;
use App\Domain\Projects\Configuration\ProviderPolicy;
use App\Domain\Projects\Configuration\ReasoningLevel;
use App\Infrastructure\AgentProviders\SimulationPlanningProvider;

function planningSource(int $documentId = 4, int $versionId = 9): PlanningSourceReference
{
    return new PlanningSourceReference($documentId, $versionId, 2, str_repeat('b', 64));
}

function planningRequest(string $scenario = 'happy_path', int $seed = 42): PlanningExecutionRequest
{
    return new PlanningExecutionRequest(
        projectId: 1,
        contextSnapshotId: 1,
        contextFingerprint: str_repeat('a', 64),
        reasoningLevel: ReasoningLevel::Medium,
        documents: [planningSource()],
        scenario: $scenario,
        seed: $seed,
    );
}

function planningTask(string $id, int $complexity = 1): PlanningTask
{
    return planningTaskWith($id, estimatedComplexity: $complexity);
}

/** @param list<PlanningAcceptanceCriterion>|null $acceptanceCriteria @param list<PlanningSourceReference>|null $sourceReferences */
function planningTaskWith(
    string $id,
    string $ticketType = 'feature',
    string $priority = 'high',
    string $risk = 'medium',
    string $logicalAgent = 'project_manager',
    int $estimatedComplexity = 1,
    string $phaseId = 'phase-one',
    string $milestoneId = 'milestone-one',
    ?array $acceptanceCriteria = null,
    ?array $sourceReferences = null,
): PlanningTask {
    $source = planningSource();

    return new PlanningTask(
        stableId: $id,
        title: 'Task '.$id,
        objective: 'Complete task '.$id,
        phaseId: $phaseId,
        milestoneId: $milestoneId,
        ticketType: $ticketType,
        scope: ['included' => ['planning'], 'excluded' => []],
        acceptanceCriteria: $acceptanceCriteria ?? [new PlanningAcceptanceCriterion('criterion-'.$id, 'Criterion '.$id, [$source])],
        sourceReferences: $sourceReferences ?? [$source],
        evidenceRequirements: ['review'],
        priority: $priority,
        risk: $risk,
        reasoningLevel: ReasoningLevel::Medium,
        logicalAgent: $logicalAgent,
        estimatedComplexity: $estimatedComplexity,
        humanApprovalRequired: true,
        reasoning: 'Required by the approved project context.',
    );
}

/** @param list<PlanningTask> $tasks @param list<PlanningDependency> $dependencies */
function planningResult(array $tasks, array $dependencies = []): PlanningExecutionResult
{
    return new PlanningExecutionResult(
        schemaVersion: 1,
        outcome: 'publishable',
        documentInventory: [new PlanningDocument(planningSource(), 'approved_project_context', 'Approved source.')],
        documentSummary: 'Approved context.',
        architectureConcerns: [],
        securityConcerns: [],
        goal: 'Deliver the project.',
        scope: ['Planning'],
        assumptions: [],
        constraints: ['Approved context only'],
        definitionOfDone: ['Roadmap approved'],
        requiredApprovals: ['roadmap'],
        roadmap: new PlanningRoadmapDefinition(
            phases: [new PlanningPhase('phase-one', 'Phase one')],
            milestones: [new PlanningMilestone('milestone-one', 'Milestone one', 'phase-one')],
            tasks: $tasks,
            dependencies: $dependencies,
        ),
    );
}

it('selects the first allowed supporting provider in fallback order', function (): void {
    $registry = new ExecutionProviderRegistry([new SimulationPlanningProvider]);

    expect($registry->resolve(ProviderPolicy::fromArray([
        'allowed_provider_ids' => ['simulation'],
        'fallback_order' => ['simulation'],
    ]), 'planning.roadmap')->id())->toBe('simulation');
});

it('produces seed-stable simulated planning results for all scenarios', function (string $scenario): void {
    $provider = new SimulationPlanningProvider;
    $request = $scenario === 'missing_documents'
        ? new PlanningExecutionRequest(1, 1, str_repeat('a', 64), ReasoningLevel::Medium, [], $scenario, 42)
        : planningRequest($scenario);

    $result = $provider->execute($request);
    (new PlanningResultValidator)->validate($result, $request);

    expect($result)->toEqual($provider->execute($request));
})->with(['happy_path', 'missing_documents', 'conflicts', 'blocked', 'human_decision_required']);

it('rejects unknown simulation scenarios', function (): void {
    (new SimulationPlanningProvider)->execute(planningRequest('unknown'));
})->throws(InvalidArgumentException::class, 'Unsupported planning simulation scenario');

it('validates structured traceability against immutable context', function (): void {
    $request = planningRequest();
    $result = (new SimulationPlanningProvider)->execute($request);

    (new PlanningResultValidator)->validate($result, $request);

    expect(true)->toBeTrue();
});

it('rejects fabricated source references', function (): void {
    $request = planningRequest();
    $task = planningTask('task-a');
    $fabricated = new PlanningSourceReference(99, 100, 1, str_repeat('c', 64));
    $task = new PlanningTask(
        stableId: $task->stableId,
        title: $task->title,
        objective: $task->objective,
        phaseId: $task->phaseId,
        milestoneId: $task->milestoneId,
        ticketType: $task->ticketType,
        scope: $task->scope,
        acceptanceCriteria: [new PlanningAcceptanceCriterion('criterion-task-a', 'Criterion', [$fabricated])],
        sourceReferences: [$fabricated],
        evidenceRequirements: $task->evidenceRequirements,
        priority: $task->priority,
        risk: $task->risk,
        reasoningLevel: $task->reasoningLevel,
        logicalAgent: $task->logicalAgent,
        estimatedComplexity: $task->estimatedComplexity,
        humanApprovalRequired: $task->humanApprovalRequired,
        reasoning: $task->reasoning,
    );

    (new PlanningResultValidator)->validate(planningResult([$task]), $request);
})->throws(InvalidArgumentException::class, 'not part of the immutable planning context');

it('rejects malformed task contract fields before persistence', function (PlanningTask $task): void {
    (new PlanningResultValidator)->validate(planningResult([$task]), planningRequest());
})->with([
    'ticket type' => fn (): PlanningTask => planningTaskWith('task-a', ticketType: 'story'),
    'priority' => fn (): PlanningTask => planningTaskWith('task-a', priority: 'urgent'),
    'risk' => fn (): PlanningTask => planningTaskWith('task-a', risk: 'unknown'),
    'logical agent' => fn (): PlanningTask => planningTaskWith('task-a', logicalAgent: 'unregistered_agent'),
    'complexity' => fn (): PlanningTask => planningTaskWith('task-a', estimatedComplexity: 14),
    'phase reference' => fn (): PlanningTask => planningTaskWith('task-a', phaseId: 'missing-phase'),
    'milestone reference' => fn (): PlanningTask => planningTaskWith('task-a', milestoneId: 'missing-milestone'),
    'source coverage' => fn (): PlanningTask => planningTaskWith('task-a', sourceReferences: []),
    'criterion coverage' => fn (): PlanningTask => planningTaskWith('task-a', acceptanceCriteria: []),
])->throws(InvalidArgumentException::class);

it('rejects duplicate stable IDs and broken dependency references', function (): void {
    $validator = new PlanningResultValidator;

    expect(fn () => $validator->validate(planningResult([planningTask('task-a'), planningTask('task-a')]), planningRequest()))
        ->toThrow(InvalidArgumentException::class, 'Task IDs must be unique')
        ->and(fn () => $validator->validate(planningResult(
            [planningTask('task-a')],
            [new PlanningDependency('task-a', 'missing-task')],
        ), planningRequest()))
        ->toThrow(InvalidArgumentException::class, 'dependency references a missing task');
});

it('calculates the weighted critical path rather than dependency depth', function (): void {
    $result = planningResult(
        [planningTask('task-a', 8), planningTask('task-b', 8), planningTask('task-c', 1), planningTask('task-d', 1)],
        [
            new PlanningDependency('task-b', 'task-a'),
            new PlanningDependency('task-c', 'task-a'),
            new PlanningDependency('task-d', 'task-c'),
        ],
    );

    $graph = (new RoadmapGraph)->analyze($result);

    expect($graph['order'])->toBe(['task-a', 'task-b', 'task-c', 'task-d'])
        ->and($graph['critical_path'])->toBe(['task-a', 'task-b'])
        ->and($graph['critical_path_rank']['task-b'])->toBe(16)
        ->and($graph['critical_path_rank']['task-d'])->toBe(10)
        ->and($graph['is_critical_path']['task-c'])->toBeFalse();
});

it('rejects dependency cycles deterministically', function (): void {
    (new RoadmapGraph)->analyze(planningResult(
        [planningTask('task-a'), planningTask('task-b')],
        [new PlanningDependency('task-a', 'task-b'), new PlanningDependency('task-b', 'task-a')],
    ));
})->throws(InvalidArgumentException::class, 'Dependency cycle detected');

it('blocks gaps and requires a human decision for consequential ambiguity', function (): void {
    $provider = new SimulationPlanningProvider;
    $evaluator = new RoadmapReadinessEvaluator;

    expect($evaluator->evaluate($provider->execute(new PlanningExecutionRequest(1, 1, str_repeat('a', 64), ReasoningLevel::Medium, [], 'missing_documents')))['decision'])->toBe('blocked')
        ->and($evaluator->evaluate($provider->execute(planningRequest('human_decision_required')))['decision'])->toBe('human_decision_required');
});

it('accepts a taskless blocked contract only with typed diagnostics', function (): void {
    $request = new PlanningExecutionRequest(1, 1, str_repeat('a', 64), ReasoningLevel::Medium, [], 'missing_documents');
    $result = (new SimulationPlanningProvider)->execute($request);

    (new PlanningResultValidator)->validate($result, $request);

    expect($result->roadmap->tasks)->toBe([])
        ->and($result->diagnostics)->toHaveCount(1)
        ->and($result->diagnostics[0])->toBeInstanceOf(PlanningDiagnostic::class);
});

it('rejects unsupported request contract versions', function (): void {
    $request = new PlanningExecutionRequest(
        projectId: 1,
        contextSnapshotId: 1,
        contextFingerprint: str_repeat('a', 64),
        reasoningLevel: ReasoningLevel::Medium,
        documents: planningRequest()->documents,
        schemaVersion: 2,
    );

    (new PlanningResultValidator)->validate((new SimulationPlanningProvider)->execute($request), $request);
})->throws(InvalidArgumentException::class, 'request schema version is unsupported');

it('edits criterion descriptions without mutating stable IDs or source references', function (): void {
    $snapshot = planningResult([planningTask('task-a')])->toArray();
    $updated = (new MaterializeRoadmap)->apply($snapshot, [
        'roadmap' => [],
        'tasks' => [
            'task-a' => [
                'acceptance_criteria' => ['criterion-task-a' => 'Updated criterion'],
            ],
        ],
    ]);
    $criterion = $updated['roadmap']['tasks'][0]['acceptance_criteria'][0];

    expect($criterion['stable_id'])->toBe('criterion-task-a')
        ->and($criterion['description'])->toBe('Updated criterion')
        ->and($criterion['source_references'])->toBe($snapshot['roadmap']['tasks'][0]['acceptance_criteria'][0]['source_references']);
});
