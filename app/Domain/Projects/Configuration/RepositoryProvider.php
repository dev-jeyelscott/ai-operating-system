<?php

declare(strict_types=1);

namespace App\Domain\Projects\Configuration;

/**
 * Defines repository providers supported by project configuration schema v1.
 */
enum RepositoryProvider: string
{
    case GitHub = 'github';
}
