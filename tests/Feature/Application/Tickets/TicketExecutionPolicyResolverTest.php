<?php

declare(strict_types=1);

use App\Application\Tickets\Data\TicketSelectionRequest;
use App\Application\Tickets\SelectNextTicketAndAcquireLease;
use App\Application\Tickets\TicketExecutionPolicyResolver;
use App\Domain\Approvals\ApprovalType;
use App\Domain\Tickets\TicketStatus;
use App\Models\Approval;
use App\Models\Execution;
use App\Models\RoadmapTask;
use Tests\Support\TicketTestFixture;

test('resolver derives provider budget retries and approvals from durable policy', function (): void {
    $fixture = TicketTestFixture::create();
    $execution = Execution::factory()->for($fixture['project'])->create([
        'project_context_snapshot_id' => $fixture['contextSnapshot']->id,
        'capability' => 'development.simulation',
        'attempt_count' => 2,
        'retry_limit' => 4,
    ]);
    Approval::factory()->approved()->for($fixture['project'])->create([
        'type' => ApprovalType::Execution,
        'request_payload' => [
            'roadmap_task_id' => $fixture['ticket']->id,
            'ticket_id' => $fixture['ticket']->stable_id,
        ],
    ]);

    $facts = app(TicketExecutionPolicyResolver::class)->resolve(
        project: $fixture['project'],
        execution: $execution,
    );

    expect($facts->providerSupportsExecution)->toBeTrue()
        ->and($facts->budgetPermitsExecution)->toBeTrue()
        ->and($facts->attemptCount)->toBe(2)
        ->and($facts->retryLimit)->toBe(4)
        ->and($facts->approvalGrantedFor($fixture['ticket']))->toBeTrue();
});

test('unsupported provider and zero budget fail closed from immutable context', function (
    array $configurationSnapshot,
    bool $providerSupported,
    bool $budgetPermitted,
): void {
    $fixture = TicketTestFixture::create(
        configurationSnapshot: $configurationSnapshot,
    );
    $execution = Execution::factory()->for($fixture['project'])->create([
        'project_context_snapshot_id' => $fixture['contextSnapshot']->id,
        'capability' => 'development.simulation',
    ]);

    $facts = app(TicketExecutionPolicyResolver::class)->resolve(
        project: $fixture['project'],
        execution: $execution,
    );

    expect($facts->providerSupportsExecution)->toBe($providerSupported)
        ->and($facts->budgetPermitsExecution)->toBe($budgetPermitted);
})->with([
    'provider unavailable' => [
        [
            'policy' => [
                'provider' => [
                    'allowed_provider_ids' => ['openai'],
                    'fallback_order' => ['openai'],
                ],
            ],
        ],
        false,
        true,
    ],
    'budget unavailable' => [
        [
            'policy' => [
                'budget' => [
                    'limit_minor' => 0,
                ],
            ],
        ],
        true,
        false,
    ],
]);

test('selection request exposes no caller controlled eligibility facts', function (): void {
    $constructor = (new ReflectionClass(TicketSelectionRequest::class))
        ->getConstructor();
    $parameterNames = array_map(
        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
        $constructor?->getParameters() ?? [],
    );

    expect($parameterNames)->toBe([
        'organizationId',
        'projectId',
        'executionId',
        'owner',
        'leaseDurationSeconds',
    ])->not->toContain('providerSupportsExecution', 'budgetPermitsExecution');
});

test('selector uses resolver policy and cannot bypass a zero budget', function (): void {
    $fixture = TicketTestFixture::create(
        ticketAttributes: [
            'status' => TicketStatus::Ready,
            'desired_state' => TicketStatus::Ready,
            'status_changed_at' => now()->subMinute(),
            'ready_at' => now()->subMinute(),
        ],
        configurationSnapshot: [
            'policy' => [
                'budget' => [
                    'limit_minor' => 0,
                ],
            ],
        ],
    );
    $fixture['roadmap']->forceFill([
        'status' => 'approved',
        'approved_fingerprint' => $fixture['roadmap']->candidate_fingerprint,
        'approved_snapshot' => ['schema_version' => 1],
        'approved_at' => now(),
    ])->save();
    $execution = Execution::factory()->for($fixture['project'])->create([
        'project_context_snapshot_id' => $fixture['contextSnapshot']->id,
        'capability' => 'development.simulation',
    ]);

    $result = app(SelectNextTicketAndAcquireLease::class)->handle(
        new TicketSelectionRequest(
            organizationId: $fixture['project']->organization_id,
            projectId: $fixture['project']->id,
            executionId: $execution->id,
            owner: 'policy-resolver-test-worker',
        ),
    );

    expect($result->isSelected())->toBeFalse()
        ->and(RoadmapTask::query()->findOrFail($fixture['ticket']->id)->status)
        ->toBe(TicketStatus::Ready);
    $this->assertDatabaseCount('ticket_execution_leases', 0);
});
