<?php

declare(strict_types=1);

namespace App\Domain\Policies;

/**
 * Identifies the deterministic rule that selected a reasoning level.
 */
enum ReasoningResolutionSource: string
{
    case ExplicitApprovedTicket = 'explicit_approved_ticket';
    case PolicyRequiredMinimum = 'policy_required_minimum';
    case TaskTypeDefault = 'task_type_default';
    case AgentRoleDefault = 'agent_role_default';
    case ProjectDefault = 'project_default';
    case SystemFallback = 'system_fallback';
    case MandatoryEscalation = 'mandatory_escalation';
}
