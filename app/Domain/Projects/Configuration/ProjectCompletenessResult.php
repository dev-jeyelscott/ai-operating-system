<?php

declare(strict_types=1);

namespace App\Domain\Projects\Configuration;

use InvalidArgumentException;

/**
 * Represents the deterministic result of evaluating project configuration
 * completeness.
 */
final readonly class ProjectCompletenessResult
{
    /**
     * @param list<ProjectCompletenessIssue> $issues
     */
    public function __construct(
        public int $projectId,
        public array $issues,
    ) {
        if ($projectId < 1) {
            throw new InvalidArgumentException(
                'The project ID must be a positive integer.',
            );
        }
    }

    /**
     * Determine whether the project has every required configuration value.
     */
    public function isComplete(): bool
    {
        return $this->issues === [];
    }

    /**
     * Return the ordered configuration keys that are still incomplete.
     *
     * @return list<string>
     */
    public function missingKeys(): array
    {
        return array_map(
            static fn (ProjectCompletenessIssue $issue): string => $issue->key,
            $this->issues,
        );
    }

    /**
     * Serialize the result into a stable, transport-safe structure.
     *
     * @return array{
     *     project_id: int,
     *     complete: bool,
     *     missing_configuration: list<array{
     *         key: string,
     *         step: string,
     *         message: string,
     *         remediation: string
     *     }>
     * }
     */
    public function toArray(): array
    {
        return [
            'project_id' => $this->projectId,
            'complete' => $this->isComplete(),
            'missing_configuration' => array_map(
                static fn (ProjectCompletenessIssue $issue): array => $issue->toArray(),
                $this->issues,
            ),
        ];
    }
}