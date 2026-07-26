<?php

declare(strict_types=1);

namespace App\Application\Policies;

use App\Application\Policies\Data\ReasoningResolution;
use App\Application\Policies\Data\ReasoningResolutionContext;
use App\Domain\Policies\ReasoningEscalationReason;
use App\Domain\Policies\ReasoningResolutionSource;
use App\Domain\Projects\Configuration\ReasoningLevel;

/**
 * Resolves reasoning through deterministic precedence and escalation rules.
 */
final class ReasoningResolver
{
    public const int POLICY_VERSION = 1;

    /**
     * Resolve the requested and effective reasoning levels without persistence.
     */
    public function resolve(
        ReasoningResolutionContext $context,
        ?ReasoningLevel $projectDefault,
    ): ReasoningResolution {
        [$requestedReasoning, $requestedSource] = $this->resolveRequestedReasoning(
            context: $context,
            projectDefault: $projectDefault,
        );

        $effectiveReasoning = $requestedReasoning;
        $resolutionSource = $requestedSource;
        $escalationReason = null;

        /*
         * A policy minimum is a floor, not an override. It may raise a weaker
         * selected default but must never lower a stronger ticket/task/role value.
         */
        if (
            $context->policyRequiredMinimum !== null
            && $this->rank($context->policyRequiredMinimum)
                > $this->rank($effectiveReasoning)
        ) {
            $effectiveReasoning = $context->policyRequiredMinimum;
            $resolutionSource = ReasoningResolutionSource::PolicyRequiredMinimum;
            $escalationReason = sprintf(
                'Policy minimum raised reasoning from %s to %s.',
                $requestedReasoning->value,
                $effectiveReasoning->value,
            );
        }

        if ($context->mandatoryEscalationReasons !== []) {
            $effectiveReasoning = ReasoningLevel::High;
            $resolutionSource = ReasoningResolutionSource::MandatoryEscalation;
            $escalationReason = $this->mandatoryEscalationSummary(
                $context->mandatoryEscalationReasons,
            );
        }

        return new ReasoningResolution(
            requestedReasoning: $requestedReasoning,
            requestedSource: $requestedSource,
            effectiveReasoning: $effectiveReasoning,
            resolutionSource: $resolutionSource,
            mandatoryEscalationReasons: $context->mandatoryEscalationReasons,
            escalationReason: $escalationReason,
        );
    }

    /**
     * Select the requested value using the approved precedence chain.
     *
     * @return array{ReasoningLevel, ReasoningResolutionSource}
     */
    private function resolveRequestedReasoning(
        ReasoningResolutionContext $context,
        ?ReasoningLevel $projectDefault,
    ): array {
        if ($context->explicitApprovedTicketReasoning !== null) {
            return [
                $context->explicitApprovedTicketReasoning,
                ReasoningResolutionSource::ExplicitApprovedTicket,
            ];
        }

        if ($context->taskTypeDefault !== null) {
            return [
                $context->taskTypeDefault,
                ReasoningResolutionSource::TaskTypeDefault,
            ];
        }

        if ($context->agentRoleDefault !== null) {
            return [
                $context->agentRoleDefault,
                ReasoningResolutionSource::AgentRoleDefault,
            ];
        }

        if ($projectDefault !== null) {
            return [
                $projectDefault,
                ReasoningResolutionSource::ProjectDefault,
            ];
        }

        return [
            ReasoningLevel::Medium,
            ReasoningResolutionSource::SystemFallback,
        ];
    }

    /**
     * Return the stable ordering used to compare reasoning levels.
     */
    private function rank(ReasoningLevel $reasoningLevel): int
    {
        return match ($reasoningLevel) {
            ReasoningLevel::Low => 1,
            ReasoningLevel::Medium => 2,
            ReasoningLevel::High => 3,
        };
    }

    /**
     * Build a safe explanation for a mandatory high-reasoning decision.
     *
     * @param  list<ReasoningEscalationReason>  $reasons
     */
    private function mandatoryEscalationSummary(array $reasons): string
    {
        return sprintf(
            'Mandatory high reasoning required by: %s.',
            implode(
                ', ',
                array_map(
                    static fn (ReasoningEscalationReason $reason): string => $reason
                        ->description(),
                    $reasons,
                ),
            ),
        );
    }
}
