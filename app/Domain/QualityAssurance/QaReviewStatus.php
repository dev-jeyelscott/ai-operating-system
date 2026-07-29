<?php

declare(strict_types=1);

namespace App\Domain\QualityAssurance;

/**
 * Defines the result of one required QA review dimension.
 */
enum QaReviewStatus: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case NotApplicable = 'not_applicable';
    case Unverified = 'unverified';
}
