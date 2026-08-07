<?php

declare(strict_types=1);

namespace App\Domain\Development;

enum DevelopmentValidationStatus: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
