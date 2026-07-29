<?php

declare(strict_types=1);

namespace App\Domain\Tickets;

/**
 * Defines stable machine-readable reasons that prevent ticket execution.
 *
 * These values are suitable for selector diagnostics, API responses, audit
 * metadata, operational dashboards, and the future no-workable-ticket UI.
 */
enum TicketIneligibilityReason: string
{
    case StatusNotEligible = 'status_not_eligible';

    case ChangesRequestedNotApproved =
        'changes_requested_not_approved';

    case DependencyIncomplete = 'dependency_incomplete';

    case BlockerUnresolved = 'blocker_unresolved';

    case ApprovalMissing = 'approval_missing';

    case ProjectNotActive = 'project_not_active';

    case ProviderUnavailable = 'provider_unavailable';

    case BudgetUnavailable = 'budget_unavailable';

    case RetryPolicyExhausted = 'retry_policy_exhausted';

    case ActiveLease = 'active_lease';
}
