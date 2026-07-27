<?php

declare(strict_types=1);

use App\Application\Approvals\Commands\DecideApproval;
use App\Application\Approvals\Commands\RequestApproval;
use App\Application\Approvals\ExpireDueApprovals;
use App\Application\Shared\Commands\CommandBus;
use App\Application\Shared\Commands\CommandResultStatus;
use App\Domain\Approvals\ApprovalDecision;
use App\Domain\Approvals\ApprovalStatus;
use App\Domain\Approvals\ApprovalType;
use App\Domain\Identity\OrganizationRole;
use App\Models\Approval;
use App\Models\Execution;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkflowInstance;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create a user with one explicit role in the project's organization.
 */
function approvalEngineUser(
    Project $project,
    OrganizationRole $role,
): User {
    $user = User::factory()->create();

    OrganizationMembership::factory()->create([
        'organization_id' => $project->organization_id,
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return $user;
}

test(
    'approval requests are idempotent and reject payload drift',
    function (): void {
        $project = Project::factory()->create();

        $owner = approvalEngineUser(
            project: $project,
            role: OrganizationRole::Owner,
        );

        $workflow = WorkflowInstance::factory()->create([
            'project_id' => $project->id,
        ]);

        $execution = Execution::factory()->create([
            'project_id' => $project->id,
            'workflow_instance_id' => $workflow->id,
        ]);

        $requestKey = sprintf(
            'approval-request:%s',
            Str::ulid(),
        );

        $correlationId = (string) Str::ulid();

        $command = new RequestApproval(
            projectId: $project->id,
            type: ApprovalType::WorkflowTransition,
            idempotencyKey: $requestKey,
            correlationId: $correlationId,
            workflowInstanceId: $workflow->id,
            executionId: $execution->id,
            requestedByUserId: $owner->id,
            expiresAt: CarbonImmutable::now()->addHour(),
            payload: [
                'transition' => 'approve_roadmap',
                'risk' => 'medium',
            ],
        );

        $bus = app(CommandBus::class);

        $first = $bus->dispatch($command);
        $replay = $bus->dispatch($command);

        $drift = $bus->dispatch(new RequestApproval(
            projectId: $project->id,
            type: ApprovalType::WorkflowTransition,
            idempotencyKey: $requestKey,
            correlationId: $correlationId,
            workflowInstanceId: $workflow->id,
            executionId: $execution->id,
            requestedByUserId: $owner->id,
            expiresAt: $command->expiresAt,
            payload: [
                'transition' => 'approve_different_roadmap',
                'risk' => 'high',
            ],
        ));

        expect($first->status)
            ->toBe(CommandResultStatus::Succeeded)
            ->and($first->data['replayed'])
            ->toBeFalse()
            ->and($replay->status)
            ->toBe(CommandResultStatus::Succeeded)
            ->and($replay->data['approval_id'])
            ->toBe($first->data['approval_id'])
            ->and($replay->data['replayed'])
            ->toBeTrue()
            ->and($drift->status)
            ->toBe(CommandResultStatus::Conflict);

        $this->assertDatabaseCount('approvals', 1);

        $this->assertDatabaseHas('approvals', [
            'id' => $first->data['approval_id'],
            'project_id' => $project->id,
            'status' => ApprovalStatus::Pending->value,
            'request_idempotency_key' => $requestKey,
        ]);

        $this->assertDatabaseCount('outbox_messages', 1);

        $this->assertDatabaseHas('outbox_messages', [
            'event_name' => 'approval.requested',
            'aggregate_id' => $first->data['approval_id'],
            'project_id' => $project->id,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'approval.requested',
            'subject_type' => 'approval',
            'subject_id' => $first->data['approval_id'],
        ]);
    },
);

test(
    'only owners and administrators may decide approvals',
    function (): void {
        $project = Project::factory()->create();

        $owner = approvalEngineUser(
            project: $project,
            role: OrganizationRole::Owner,
        );

        $member = approvalEngineUser(
            project: $project,
            role: OrganizationRole::Member,
        );

        $bus = app(CommandBus::class);

        $request = $bus->dispatch(new RequestApproval(
            projectId: $project->id,
            type: ApprovalType::Roadmap,
            idempotencyKey: sprintf(
                'approval-request:%s',
                Str::ulid(),
            ),
            correlationId: (string) Str::ulid(),
            requestedByUserId: $owner->id,
            expiresAt: CarbonImmutable::now()->addHour(),
            payload: [
                'roadmap_version' => 1,
            ],
        ));

        $approvalId = (string) $request->data['approval_id'];

        expect(
            fn () => $bus->dispatch(new DecideApproval(
                approvalId: $approvalId,
                actorUserId: $member->id,
                decision: ApprovalDecision::Approve,
                idempotencyKey: sprintf(
                    'approval-decision:%s',
                    Str::ulid(),
                ),
                correlationId: (string) Str::ulid(),
                reason: 'A member must not be allowed to approve.',
            )),
        )->toThrow(AuthorizationException::class);

        $decisionKey = sprintf(
            'approval-decision:%s',
            Str::ulid(),
        );

        $decision = new DecideApproval(
            approvalId: $approvalId,
            actorUserId: $owner->id,
            decision: ApprovalDecision::Approve,
            idempotencyKey: $decisionKey,
            correlationId: (string) Str::ulid(),
            reason: 'Roadmap scope and risks are accepted.',
        );

        $first = $bus->dispatch($decision);
        $replay = $bus->dispatch($decision);

        $conflictingDecision = $bus->dispatch(
            new DecideApproval(
                approvalId: $approvalId,
                actorUserId: $owner->id,
                decision: ApprovalDecision::Reject,
                idempotencyKey: sprintf(
                    'approval-decision:%s',
                    Str::ulid(),
                ),
                correlationId: (string) Str::ulid(),
                reason: 'Attempt to replace an existing approval.',
            ),
        );

        expect($first->status)
            ->toBe(CommandResultStatus::Succeeded)
            ->and($first->data['replayed'])
            ->toBeFalse()
            ->and($replay->status)
            ->toBe(CommandResultStatus::Succeeded)
            ->and($replay->data['replayed'])
            ->toBeTrue()
            ->and($conflictingDecision->status)
            ->toBe(CommandResultStatus::Conflict);

        $this->assertDatabaseHas('approvals', [
            'id' => $approvalId,
            'status' => ApprovalStatus::Approved->value,
            'decided_by_user_id' => $owner->id,
            'decision_idempotency_key' => $decisionKey,
        ]);

        expect(
            Approval::query()
                ->whereKey($approvalId)
                ->value('decision_reason'),
        )->toBe('Roadmap scope and risks are accepted.');

        expect(
            DB::table('outbox_messages')
                ->where('event_name', 'approval.granted')
                ->where('aggregate_id', $approvalId)
                ->count(),
        )->toBe(1);

        expect(
            DB::table('audit_events')
                ->where('event_type', 'approval.granted')
                ->where('subject_id', $approvalId)
                ->count(),
        )->toBe(1);
    },
);

test(
    'due approvals expire exactly once',
    function (): void {
        $project = Project::factory()->create();

        $approval = Approval::factory()->create([
            'project_id' => $project->id,
            'status' => ApprovalStatus::Pending,
            'expires_at' => CarbonImmutable::now()->subMinute(),
        ]);

        $expirer = app(ExpireDueApprovals::class);

        $firstCount = $expirer->handle();
        $secondCount = $expirer->handle();

        expect($firstCount)
            ->toBe(1)
            ->and($secondCount)
            ->toBe(0)
            ->and($approval->refresh()->status)
            ->toBe(ApprovalStatus::Expired);

        expect(
            DB::table('outbox_messages')
                ->where('event_name', 'approval.expired')
                ->where('aggregate_id', $approval->id)
                ->count(),
        )->toBe(1);

        expect(
            DB::table('audit_events')
                ->where('event_type', 'approval.expired')
                ->where('subject_id', $approval->id)
                ->count(),
        )->toBe(1);
    },
);

test(
    'approval references cannot cross project boundaries',
    function (): void {
        $project = Project::factory()->create();
        $otherProject = Project::factory()->create();

        $owner = approvalEngineUser(
            project: $project,
            role: OrganizationRole::Owner,
        );

        $otherWorkflow = WorkflowInstance::factory()->create([
            'project_id' => $otherProject->id,
        ]);

        $result = app(CommandBus::class)->dispatch(
            new RequestApproval(
                projectId: $project->id,
                type: ApprovalType::WorkflowTransition,
                idempotencyKey: sprintf(
                    'approval-request:%s',
                    Str::ulid(),
                ),
                correlationId: (string) Str::ulid(),
                workflowInstanceId: $otherWorkflow->id,
                requestedByUserId: $owner->id,
                expiresAt: CarbonImmutable::now()->addHour(),
            ),
        );

        expect($result->status)
            ->toBe(CommandResultStatus::ValidationFailed);

        $this->assertDatabaseCount('approvals', 0);
    },
);
