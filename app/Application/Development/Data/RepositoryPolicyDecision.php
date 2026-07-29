<?php

declare(strict_types=1);

namespace App\Application\Development\Data;

use App\Domain\Development\RepositoryPolicyFailureReason;

final readonly class RepositoryPolicyDecision
{
    private function __construct(public bool $allowed, public ?RepositoryPolicyFailureReason $reason) {}

    public static function allowed(): self
    {
        return new self(true, null);
    }

    public static function rejected(RepositoryPolicyFailureReason $reason): self
    {
        return new self(false, $reason);
    }
}
