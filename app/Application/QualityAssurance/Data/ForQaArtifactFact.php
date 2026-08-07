<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance\Data;

use InvalidArgumentException;

/**
 * Represents one immutable artifact fact supplied to the QA eligibility policy.
 *
 * The object contains only the information required by eligibility evaluation.
 * It does not retrieve, mutate, or trust external provider state directly.
 */
final readonly class ForQaArtifactFact
{
    /**
     * Store metadata after validating that every key is a string.
     *
     * @var array<string, mixed>
     */
    public array $metadata;

    /**
     * Create and validate one artifact fact.
     *
     * Metadata enters as an untrusted general PHP array. After validating every
     * key, it is stored as the canonical string-keyed metadata structure.
     *
     * @param  array<array-key, mixed>  $metadata
     */
    public function __construct(
        public string $type,
        public string $executionId,
        public int $executionAttemptId,
        public bool $hasEvidence,
        array $metadata,
    ) {
        if (
            $this->type === ''
            || trim($this->type) !== $this->type
        ) {
            throw new InvalidArgumentException(
                'QA artifact type must be a trimmed non-empty string.',
            );
        }

        if (
            $this->executionId === ''
            || trim($this->executionId) !== $this->executionId
        ) {
            throw new InvalidArgumentException(
                'QA artifact execution ID must be a trimmed non-empty string.',
            );
        }

        if ($this->executionAttemptId < 1) {
            throw new InvalidArgumentException(
                'QA artifact execution-attempt ID must be positive.',
            );
        }

        foreach (array_keys($metadata) as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException(
                    'QA artifact metadata must use string keys.',
                );
            }
        }

        /** @var array<string, mixed> $metadata */
        $this->metadata = $metadata;
    }
}
