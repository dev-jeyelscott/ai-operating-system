<?php

declare(strict_types=1);

use App\Application\Policies\Data\ReasoningResolutionContext;
use App\Application\Policies\Exceptions\PolicyDecisionConflict;
use App\Application\Policies\ReasoningResolver;
use App\Application\Policies\ResolveExecutionReasoningDecision;
use App\Domain\Policies\ReasoningEscalationReason;
use App\Domain\Policies\ReasoningResolutionSource;
use App\Domain\Projects\Configuration\ReasoningLevel;
use App\Models\Execution;
use App\Models\PolicyDecision;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('persists the project-default reasoning decision and provenance', function (): void {
    $project = Project::factory()->withConfiguration()->create();

    $project->configuration()->update([
        'default_reasoning' => ReasoningLevel::Low,
    ]);

    $execution = Execution::factory()->create([
        'project_id' => $project->id,
        'requested_reasoning_level' => ReasoningLevel::Low,
    ]);

    $decision = app(ResolveExecutionReasoningDecision::class)->resolve(
        execution: $execution,
        context: new ReasoningResolutionContext,
    );

    expect($decision->project->is($project))
        ->toBeTrue()
        ->and($decision->execution->is($execution))
        ->toBeTrue()
        ->and($decision->policy_version)
        ->toBe(ReasoningResolver::POLICY_VERSION)
        ->and($decision->requested_reasoning_level)
        ->toBe(ReasoningLevel::Low)
        ->and($decision->requested_reasoning_source)
        ->toBe(ReasoningResolutionSource::ProjectDefault)
        ->and($decision->effective_reasoning_level)
        ->toBe(ReasoningLevel::Low)
        ->and($decision->reasoning_resolution_source)
        ->toBe(ReasoningResolutionSource::ProjectDefault)
        ->and($decision->input_snapshot['project_configuration_revision'])
        ->toBe($project->configuration()->firstOrFail()->revision)
        ->and($decision->input_fingerprint)
        ->toMatch('/\A[a-f0-9]{64}\z/');
});

it('persists mandatory escalation as effective high reasoning', function (): void {
    $project = Project::factory()->withConfiguration()->create();

    $execution = Execution::factory()->create([
        'project_id' => $project->id,
        'requested_reasoning_level' => ReasoningLevel::Low,
    ]);

    $decision = app(ResolveExecutionReasoningDecision::class)->resolve(
        execution: $execution,
        context: new ReasoningResolutionContext(
            explicitApprovedTicketReasoning: ReasoningLevel::Low,
            mandatoryEscalationReasons: [
                ReasoningEscalationReason::Security,
            ],
        ),
    );

    expect($decision->requested_reasoning_level)
        ->toBe(ReasoningLevel::Low)
        ->and($decision->requested_reasoning_source)
        ->toBe(ReasoningResolutionSource::ExplicitApprovedTicket)
        ->and($decision->effective_reasoning_level)
        ->toBe(ReasoningLevel::High)
        ->and($decision->reasoning_resolution_source)
        ->toBe(ReasoningResolutionSource::MandatoryEscalation)
        ->and($decision->reasoning_escalation_reasons)
        ->toBe(['security'])
        ->and($decision->reasoning_escalation_reason)
        ->toContain('security-sensitive work');
});

it('persists a policy minimum that raises the effective level', function (): void {
    $project = Project::factory()->withConfiguration()->create();

    $execution = Execution::factory()->create([
        'project_id' => $project->id,
        'requested_reasoning_level' => ReasoningLevel::Low,
    ]);

    $decision = app(ResolveExecutionReasoningDecision::class)->resolve(
        execution: $execution,
        context: new ReasoningResolutionContext(
            explicitApprovedTicketReasoning: ReasoningLevel::Low,
            policyRequiredMinimum: ReasoningLevel::High,
        ),
    );

    expect($decision->requested_reasoning_level)
        ->toBe(ReasoningLevel::Low)
        ->and($decision->effective_reasoning_level)
        ->toBe(ReasoningLevel::High)
        ->and($decision->reasoning_resolution_source)
        ->toBe(ReasoningResolutionSource::PolicyRequiredMinimum)
        ->and($decision->reasoning_escalation_reasons)
        ->toBe([])
        ->and($decision->reasoning_escalation_reason)
        ->toContain('low to high');
});

it('returns the existing decision for an identical replay', function (): void {
    $project = Project::factory()->withConfiguration()->create();

    $execution = Execution::factory()->create([
        'project_id' => $project->id,
        'requested_reasoning_level' => ReasoningLevel::Medium,
    ]);

    $service = app(ResolveExecutionReasoningDecision::class);
    $context = new ReasoningResolutionContext(
        explicitApprovedTicketReasoning: ReasoningLevel::Medium,
    );

    $first = $service->resolve($execution, $context);
    $second = $service->resolve($execution, $context);

    expect($second->id)
        ->toBe($first->id)
        ->and(PolicyDecision::query()->count())
        ->toBe(1);
});

it('rejects a replay that changes immutable decision inputs', function (): void {
    $project = Project::factory()->withConfiguration()->create();

    $execution = Execution::factory()->create([
        'project_id' => $project->id,
        'requested_reasoning_level' => ReasoningLevel::Low,
    ]);

    $service = app(ResolveExecutionReasoningDecision::class);

    $service->resolve(
        execution: $execution,
        context: new ReasoningResolutionContext(
            explicitApprovedTicketReasoning: ReasoningLevel::Low,
        ),
    );

    expect(
        fn () => $service->resolve(
            execution: $execution,
            context: new ReasoningResolutionContext(
                explicitApprovedTicketReasoning: ReasoningLevel::Low,
                mandatoryEscalationReasons: [
                    ReasoningEscalationReason::MergeDecision,
                ],
            ),
        ),
    )->toThrow(
        PolicyDecisionConflict::class,
        'already has a reasoning decision with different inputs',
    );
});

it('rejects an execution whose immutable requested level differs from resolution', function (): void {
    $project = Project::factory()->withConfiguration()->create();

    $execution = Execution::factory()->create([
        'project_id' => $project->id,
        'requested_reasoning_level' => ReasoningLevel::Medium,
    ]);

    expect(
        fn () => app(ResolveExecutionReasoningDecision::class)->resolve(
            execution: $execution,
            context: new ReasoningResolutionContext(
                explicitApprovedTicketReasoning: ReasoningLevel::Low,
            ),
        ),
    )->toThrow(
        PolicyDecisionConflict::class,
        'but policy resolved [low]',
    );
});

it('protects policy decisions from eloquent update and delete operations', function (): void {
    $project = Project::factory()->withConfiguration()->create();

    $execution = Execution::factory()->create([
        'project_id' => $project->id,
        'requested_reasoning_level' => ReasoningLevel::Medium,
    ]);

    $decision = app(ResolveExecutionReasoningDecision::class)->resolve(
        execution: $execution,
        context: new ReasoningResolutionContext,
    );

    $decision->forceFill([
        'effective_reasoning_level' => ReasoningLevel::High,
    ]);

    expect(fn () => $decision->save())
        ->toThrow(LogicException::class, 'Policy decisions are immutable.');

    $decision->refresh();

    expect(fn () => $decision->delete())
        ->toThrow(
            LogicException::class,
            'Policy decisions cannot be deleted.',
        );
});

it('protects policy decisions from direct database mutation', function (): void {
    $project = Project::factory()->withConfiguration()->create();

    $execution = Execution::factory()->create([
        'project_id' => $project->id,
        'requested_reasoning_level' => ReasoningLevel::Medium,
    ]);

    $decision = app(ResolveExecutionReasoningDecision::class)->resolve(
        execution: $execution,
        context: new ReasoningResolutionContext,
    );

    expect(
        fn () => DB::table('policy_decisions')
            ->where('id', $decision->id)
            ->update(['effective_reasoning_level' => 'high']),
    )->toThrow(QueryException::class);
});
