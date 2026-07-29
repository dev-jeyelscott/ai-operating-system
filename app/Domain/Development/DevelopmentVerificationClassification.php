<?php

declare(strict_types=1);

namespace App\Domain\Development;

enum DevelopmentVerificationClassification: string
{
    case Unverified = 'unverified';
}
