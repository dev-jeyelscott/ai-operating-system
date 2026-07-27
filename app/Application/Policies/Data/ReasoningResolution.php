<?php

declare(strict_types=1);

namespace App\Application\Policies\Data;

use App\Domain\Policies\ReasoningEscalationReason;
use App\Domain\Policies\ReasoningResolutionSource;
use App\Domain\Projects\Configuration\ReasoningLevel;

/**
 * Represents one deterministic reasoning-resolution result.
 */
final readonly class ReasoningResolution
{
    /**
     * @param  list<ReasoningEscalationReason>  $mandatoryEscalationReasons
     */
    public function __construct(
        public ReasoningLevel $requestedReasoning,
        public ReasoningResolutionSource $requestedSource,
        public ReasoningLevel $effectiveReasoning,
        public ReasoningResolutionSource $resolutionSource,
        public array $mandatoryEscalationReasons,
        public ?string $escalationReason,
    ) {}
}
