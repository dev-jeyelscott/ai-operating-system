<?php

declare(strict_types=1);

namespace App\Domain\QualityAssurance;

/**
 * Defines the closed set of Layer 3 QA and merge-advisory decisions.
 */
enum QaDecision: string
{
    case MergeReady = 'merge_ready';
    case MergeReadyWithRisks = 'merge_ready_with_risks';
    case ChangesRequested = 'changes_requested';
    case Blocked = 'blocked';
    case HumanReviewRequired = 'human_review_required';
}
