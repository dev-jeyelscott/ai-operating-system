<?php

declare(strict_types=1);

use App\Application\Operations\BuildOfficeProjection;
use App\Domain\Audit\AuditEventType;
use App\Domain\Executions\ExecutionStatus;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\OfficeProjection;
use App\Models\OutboxMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TicketTestFixture;

uses(RefreshDatabase::class);

/**
 * Persist one sanitized provider lifecycle event with an intentionally unsafe
 * payload value proving that provider payload content is not projected.
 */
function aios253ProviderEvent(
    int $organizationId,
    int $projectId,
    string $executionId,
    AuditEventType $type,
    string $unsafePayload = 'secret-provider-payload',
): OutboxMessage {
    return OutboxMessage::query()->create([
        'event_id' => (string) Str::ulid(),
        'event_name' => $type->value,
        'aggregate_type' => 'provider_session',
        'aggregate_id' => (string) Str::ulid(),
        'organization_id' => $organizationId,
        'project_id' => $projectId,
        'occurred_at' => now(),
        'correlation_id' => (string) Str::ulid(),
        'causation_id' => null,
        'execution_id' => $executionId,
        'schema_version' => 1,
        'envelope' => [
            'provider' => 'codex',
            'payload' => [
                'raw_prompt' => $unsafePayload,
                'raw_command' => 'do-not-project-this',
            ],
        ],
        'published_at' => now(),
        'created_at' => now(),
    ]);
}

it('projects real Codex planning activity from durable sanitized events', function (): void {
    $fixture = TicketTestFixture::create(
        stableId: 'AIOS-253-CODEX-OFFICE',
    );

    $project = $fixture['project'];

    $execution = Execution::factory()
        ->for($project)
        ->create([
            'capability' => 'planning.generate',
            'logical_role' => 'software_architect',
            'status' => ExecutionStatus::Running,
            'attempt_count' => 1,
            'retry_limit' => 2,
            'started_at' => now()->subSeconds(10),
        ]);

    ExecutionAttempt::factory()
        ->for($execution, 'execution')
        ->running()
        ->create([
            'attempt_number' => 1,
            'execution_provider' => 'codex',
            'model_identifier' => 'gpt-5.3-codex',
            'estimated_cost' => '0.012345',
            'cost_currency' => 'USD',
        ]);

    aios253ProviderEvent(
        organizationId: $project->organization_id,
        projectId: $project->id,
        executionId: $execution->id,
        type: AuditEventType::ProviderTurnStarted,
    );

    $approvalEvent = aios253ProviderEvent(
        organizationId: $project->organization_id,
        projectId: $project->id,
        executionId: $execution->id,
        type: AuditEventType::ProviderApprovalRequested,
    );

    $projection = app(BuildOfficeProjection::class)->handle(
        organizationId: $project->organization_id,
        projectId: $project->id,
    );

    $agent = collect($projection->state['agents'] ?? [])
        ->firstWhere('id', $execution->id);

    expect($agent)->toBeArray()
        ->and($agent['provider'])->toBe('codex')
        ->and($agent['model'])->toBe('gpt-5.3-codex')
        ->and($agent['officeState'])->toBe('waiting_for_approval')
        ->and($agent['room'])->toBe('approval_room')
        ->and($agent['currentAction'])
        ->toBe('Waiting for an authorized provider decision')
        ->and($projection->last_event_sequence)
        ->toBe($approvalEvent->sequence)
        ->and($projection->state['simulation']['labelRequired'])
        ->toBeFalse();

    $activity = $projection->state['activity'] ?? [];

    expect($activity)->toHaveCount(2)
        ->and($activity[0]['state'])->toBe('planning')
        ->and($activity[1]['state'])->toBe('waiting_for_approval')
        ->and($activity[1]['sequence'])->toBe($approvalEvent->sequence);

    $serialized = json_encode(
        $projection->state,
        JSON_THROW_ON_ERROR,
    );

    expect($serialized)
        ->not->toContain('secret-provider-payload')
        ->not->toContain('do-not-project-this')
        ->not->toContain('raw_prompt')
        ->not->toContain('raw_command');
});

it('keeps workflow blockers failures and cancellation above provider progress', function (): void {
    $fixture = TicketTestFixture::create(
        stableId: 'AIOS-253-PRECEDENCE',
    );

    $project = $fixture['project'];

    $execution = Execution::factory()
        ->for($project)
        ->create([
            'capability' => 'planning.generate',
            'logical_role' => 'software_architect',
            'status' => ExecutionStatus::Running,
            'attempt_count' => 1,
        ]);

    ExecutionAttempt::factory()
        ->for($execution, 'execution')
        ->running()
        ->create([
            'attempt_number' => 1,
            'execution_provider' => 'codex',
            'model_identifier' => 'gpt-5.3-codex',
        ]);

    aios253ProviderEvent(
        organizationId: $project->organization_id,
        projectId: $project->id,
        executionId: $execution->id,
        type: AuditEventType::ProviderApprovalRequested,
    );

    $execution->forceFill([
        'status' => ExecutionStatus::Blocked,
    ])->save();

    $blocked = app(BuildOfficeProjection::class)->handle(
        organizationId: $project->organization_id,
        projectId: $project->id,
    );

    $blockedAgent = collect($blocked->state['agents'] ?? [])
        ->firstWhere('id', $execution->id);

    expect($blockedAgent['officeState'])->toBe('blocked')
        ->and($blockedAgent['room'])->toBe('operations_area');

    $execution->forceFill([
        'status' => ExecutionStatus::Failed,
    ])->save();

    $failed = app(BuildOfficeProjection::class)->handle(
        organizationId: $project->organization_id,
        projectId: $project->id,
    );

    $failedAgent = collect($failed->state['agents'] ?? [])
        ->firstWhere('id', $execution->id);

    expect($failedAgent['officeState'])->toBe('failed');

    $execution->forceFill([
        'status' => ExecutionStatus::Cancelled,
    ])->save();

    $cancelled = app(BuildOfficeProjection::class)->handle(
        organizationId: $project->organization_id,
        projectId: $project->id,
    );

    $cancelledAgent = collect($cancelled->state['agents'] ?? [])
        ->firstWhere('id', $execution->id);

    expect($cancelledAgent['officeState'])->toBe('cancelled')
        ->and($cancelledAgent['room'])->toBe('operations_area')
        ->and(OfficeProjection::query()->count())->toBe(1);
});
