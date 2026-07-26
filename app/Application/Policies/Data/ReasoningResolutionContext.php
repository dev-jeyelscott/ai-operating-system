<?php

declare(strict_types=1);

namespace App\Application\Policies\Data;

use App\Domain\Policies\ReasoningEscalationReason;
use App\Domain\Projects\Configuration\ReasoningLevel;

/**
 * Carries only the stable, credential-free inputs used by reasoning policy.
 */
final readonly class ReasoningResolutionContext
{
    /** @var list<ReasoningEscalationReason> */
    public array $mandatoryEscalationReasons;

    /**
     * Normalize mandatory reasons so fingerprints remain deterministic.
     *
     * @param  list<ReasoningEscalationReason>  $mandatoryEscalationReasons
     */
    public function __construct(
        public ?ReasoningLevel $explicitApprovedTicketReasoning = null,
        public ?ReasoningLevel $policyRequiredMinimum = null,
        public ?ReasoningLevel $taskTypeDefault = null,
        public ?ReasoningLevel $agentRoleDefault = null,
        array $mandatoryEscalationReasons = [],
    ) {
        $normalizedReasons = [];

        foreach ($mandatoryEscalationReasons as $reason) {
            $normalizedReasons[$reason->value] = $reason;
        }

        ksort($normalizedReasons, SORT_STRING);

        $this->mandatoryEscalationReasons = array_values($normalizedReasons);
    }

    /**
     * Return the stable credential-free input snapshot persisted with a decision.
     *
     * @return array{
     *     explicit_approved_ticket_reasoning: string|null,
     *     policy_required_minimum: string|null,
     *     task_type_default: string|null,
     *     agent_role_default: string|null,
     *     project_default: string|null,
     *     project_configuration_revision: int|null,
     *     system_fallback: string,
     *     mandatory_escalation_reasons: list<string>
     * }
     */
    public function toInputSnapshot(
        ?ReasoningLevel $projectDefault,
        ?int $projectConfigurationRevision,
    ): array {
        return [
            'explicit_approved_ticket_reasoning' => $this
                ->explicitApprovedTicketReasoning?->value,
            'policy_required_minimum' => $this
                ->policyRequiredMinimum?->value,
            'task_type_default' => $this->taskTypeDefault?->value,
            'agent_role_default' => $this->agentRoleDefault?->value,
            'project_default' => $projectDefault?->value,
            'project_configuration_revision' => $projectConfigurationRevision,
            'system_fallback' => ReasoningLevel::Medium->value,
            'mandatory_escalation_reasons' => array_map(
                static fn (
                    ReasoningEscalationReason $reason,
                ): string => $reason->value,
                $this->mandatoryEscalationReasons,
            ),
        ];
    }
}
