<?php

declare(strict_types=1);

namespace App\Application\Planning\Data;

use InvalidArgumentException;

/**
 * Complete deterministic output of one Codex planning assembly pass.
 */
final readonly class AssembledCodexPlanningContext
{
    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $outputSchema
     */
    public function __construct(
        public array $manifest,
        public string $manifestFingerprint,
        public string $renderedInstructions,
        public array $outputSchema,
    ) {
        if (
            $this->manifest === []
            || preg_match('/\A[a-f0-9]{64}\z/D', $this->manifestFingerprint) !== 1
            || $this->renderedInstructions === ''
            || ($this->outputSchema['type'] ?? null) !== 'object'
        ) {
            throw new InvalidArgumentException(
                'Assembled Codex planning context is invalid.',
            );
        }
    }
}
