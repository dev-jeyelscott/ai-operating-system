<?php

declare(strict_types=1);

namespace App\Domain\Projects\Configuration;

use App\Domain\Projects\ProjectSetupStep;
use InvalidArgumentException;

/**
 * Describes one deterministic project-configuration blocker.
 */
final readonly class ProjectCompletenessIssue
{
    /**
     * Create one machine-readable and human-actionable completeness issue.
     */
    public function __construct(
        public string $key,
        public ProjectSetupStep $step,
        public string $message,
        public string $remediation,
    ) {
        if (
            preg_match(
                '/\A[a-z0-9_]+(?:\.[a-z0-9_]+)*\z/D',
                $key,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'A project completeness issue key must use dot-delimited snake_case segments.',
            );
        }

        if (trim($message) === '') {
            throw new InvalidArgumentException(
                'A project completeness issue message cannot be empty.',
            );
        }

        if (trim($remediation) === '') {
            throw new InvalidArgumentException(
                'A project completeness remediation cannot be empty.',
            );
        }
    }

    /**
     * Serialize the issue without exposing credentials or persistence internals.
     *
     * @return array{
     *     key: string,
     *     step: string,
     *     message: string,
     *     remediation: string
     * }
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'step' => $this->step->value,
            'message' => $this->message,
            'remediation' => $this->remediation,
        ];
    }
}
