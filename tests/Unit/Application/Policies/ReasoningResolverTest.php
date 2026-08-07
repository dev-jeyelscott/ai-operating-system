<?php

declare(strict_types=1);

use App\Application\Policies\Data\ReasoningResolutionContext;
use App\Application\Policies\ReasoningResolver;
use App\Domain\Policies\ReasoningEscalationReason;
use App\Domain\Policies\ReasoningResolutionSource;
use App\Domain\Projects\Configuration\ReasoningLevel;

it('uses the approved ticket value before task role and project defaults', function (): void {
    $resolution = (new ReasoningResolver)->resolve(
        context: new ReasoningResolutionContext(
            explicitApprovedTicketReasoning: ReasoningLevel::Low,
            taskTypeDefault: ReasoningLevel::High,
            agentRoleDefault: ReasoningLevel::High,
        ),
        projectDefault: ReasoningLevel::High,
    );

    expect($resolution->requestedReasoning)
        ->toBe(ReasoningLevel::Low)
        ->and($resolution->requestedSource)
        ->toBe(ReasoningResolutionSource::ExplicitApprovedTicket)
        ->and($resolution->effectiveReasoning)
        ->toBe(ReasoningLevel::Low)
        ->and($resolution->resolutionSource)
        ->toBe(ReasoningResolutionSource::ExplicitApprovedTicket);
});

it('uses task role project and system defaults in order', function (
    ReasoningResolutionContext $context,
    ?ReasoningLevel $projectDefault,
    ReasoningLevel $expectedReasoning,
    ReasoningResolutionSource $expectedSource,
): void {
    $resolution = (new ReasoningResolver)->resolve(
        context: $context,
        projectDefault: $projectDefault,
    );

    expect($resolution->requestedReasoning)
        ->toBe($expectedReasoning)
        ->and($resolution->requestedSource)
        ->toBe($expectedSource);
})->with([
    'task type default' => [
        new ReasoningResolutionContext(
            taskTypeDefault: ReasoningLevel::Low,
            agentRoleDefault: ReasoningLevel::High,
        ),
        ReasoningLevel::High,
        ReasoningLevel::Low,
        ReasoningResolutionSource::TaskTypeDefault,
    ],
    'agent role default' => [
        new ReasoningResolutionContext(
            agentRoleDefault: ReasoningLevel::High,
        ),
        ReasoningLevel::Low,
        ReasoningLevel::High,
        ReasoningResolutionSource::AgentRoleDefault,
    ],
    'project default' => [
        new ReasoningResolutionContext,
        ReasoningLevel::Low,
        ReasoningLevel::Low,
        ReasoningResolutionSource::ProjectDefault,
    ],
    'system fallback' => [
        new ReasoningResolutionContext,
        null,
        ReasoningLevel::Medium,
        ReasoningResolutionSource::SystemFallback,
    ],
]);

it('raises a weaker request to the policy minimum without lowering stronger defaults', function (): void {
    $resolver = new ReasoningResolver;

    $raised = $resolver->resolve(
        context: new ReasoningResolutionContext(
            explicitApprovedTicketReasoning: ReasoningLevel::Low,
            policyRequiredMinimum: ReasoningLevel::High,
        ),
        projectDefault: ReasoningLevel::Medium,
    );

    $preserved = $resolver->resolve(
        context: new ReasoningResolutionContext(
            taskTypeDefault: ReasoningLevel::High,
            policyRequiredMinimum: ReasoningLevel::Medium,
        ),
        projectDefault: ReasoningLevel::Low,
    );

    expect($raised->requestedReasoning)
        ->toBe(ReasoningLevel::Low)
        ->and($raised->effectiveReasoning)
        ->toBe(ReasoningLevel::High)
        ->and($raised->resolutionSource)
        ->toBe(ReasoningResolutionSource::PolicyRequiredMinimum)
        ->and($raised->escalationReason)
        ->toContain('low to high')
        ->and($preserved->effectiveReasoning)
        ->toBe(ReasoningLevel::High)
        ->and($preserved->resolutionSource)
        ->toBe(ReasoningResolutionSource::TaskTypeDefault);
});

it('forces high reasoning for every mandatory escalation condition', function (
    ReasoningEscalationReason $reason,
): void {
    $resolution = (new ReasoningResolver)->resolve(
        context: new ReasoningResolutionContext(
            explicitApprovedTicketReasoning: ReasoningLevel::Low,
            mandatoryEscalationReasons: [$reason],
        ),
        projectDefault: ReasoningLevel::Low,
    );

    expect($resolution->requestedReasoning)
        ->toBe(ReasoningLevel::Low)
        ->and($resolution->effectiveReasoning)
        ->toBe(ReasoningLevel::High)
        ->and($resolution->resolutionSource)
        ->toBe(ReasoningResolutionSource::MandatoryEscalation)
        ->and($resolution->mandatoryEscalationReasons)
        ->toBe([$reason])
        ->and($resolution->escalationReason)
        ->toContain($reason->description());
})->with(ReasoningEscalationReason::cases());

it('deduplicates and sorts mandatory escalation reasons for stable fingerprints', function (): void {
    $context = new ReasoningResolutionContext(
        mandatoryEscalationReasons: [
            ReasoningEscalationReason::Security,
            ReasoningEscalationReason::Authorization,
            ReasoningEscalationReason::Security,
        ],
    );

    expect($context->mandatoryEscalationReasons)->toBe([
        ReasoningEscalationReason::Authorization,
        ReasoningEscalationReason::Security,
    ]);
});
