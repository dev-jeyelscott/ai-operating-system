<?php

declare(strict_types=1);

namespace App\Domain\Development;

enum DevelopmentStageStatus: string
{
    case Pending = 'pending';
    case Skipped = 'skipped';
    case Passed = 'passed';
    case Failed = 'failed';
}
