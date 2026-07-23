<?php

declare(strict_types=1);

namespace App\Domain\Projects\Configuration;

use InvalidArgumentException;

/**
 * Represents the deterministic result of evaluating whether a project has all
 * configuration required to begin execution.
 */
final readonly class ProjectCompletenessResult
{
    /**
     * @param  list<ProjectCompletenessIssue>  $issues
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
     * Create a completeness result for one project.
     *
     * This named constructor keeps result creation consistent across evaluator
     * exit paths, including missing configuration and unsupported schemas.
     *
     * @param  list<ProjectCompletenessIssue>  $issues
     */
    public static function forProject(
        int $projectId,
        array $issues,
    ): self {
        return new self(
            projectId: $projectId,
            issues: $issues,
        );
    }

    /**
     * Determine whether no configuration blockers remain.
     */
    public function isComplete(): bool
    {
        return $this->issues === [];
    }

    /**
     * Return the ordered keys of missing or invalid configuration.
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
     * Serialize the result without exposing credentials or secret material.
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
                static fn (
                    ProjectCompletenessIssue $issue,
                ): array => $issue->toArray(),
                $this->issues,
            ),
        ];
    }
}
