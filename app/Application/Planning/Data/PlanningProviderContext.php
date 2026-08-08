<?php

declare(strict_types=1);

namespace App\Application\Planning\Data;

use InvalidArgumentException;

/**
 * Provider-neutral pointer to one fully assembled immutable context artifact.
 */
final readonly class PlanningProviderContext
{
    /** @param array<string, mixed> $outputSchema */
    public function __construct(
        public string $executionId,
        public int $executionAttemptId,
        public int $manifestArtifactId,
        public string $manifestFingerprint,
        public string $templateVersion,
        public string $renderedInstructions,
        public array $outputSchema,
    ) {
        if (
            $this->executionId === ''
            || $this->executionAttemptId < 1
            || $this->manifestArtifactId < 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $this->manifestFingerprint) !== 1
            || $this->templateVersion === ''
            || $this->renderedInstructions === ''
            || $this->outputSchema === []
        ) {
            throw new InvalidArgumentException(
                'Planning provider context is incomplete.',
            );
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'execution_id' => $this->executionId,
            'execution_attempt_id' => $this->executionAttemptId,
            'manifest_artifact_id' => $this->manifestArtifactId,
            'manifest_fingerprint' => $this->manifestFingerprint,
            'template_version' => $this->templateVersion,
            'rendered_instructions' => $this->renderedInstructions,
            'output_schema' => $this->outputSchema,
        ];
    }
}
