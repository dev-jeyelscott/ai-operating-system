<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Application\Projects\Contracts\ProjectRepository;
use App\Domain\Projects\ProjectType;
use App\Models\Project;
use InvalidArgumentException;

/**
 * Updates mutable project metadata without modifying aggregate identity,
 * organization ownership, archive state, or workflow status.
 */
final readonly class UpdateProject
{
    /**
     * Inject the tenant-safe project persistence contract.
     */
    public function __construct(
        private ProjectRepository $projects,
    ) {}

    /**
     * Apply validated metadata inside the expected organization boundary.
     */
    public function handle(
        int $organizationId,
        int $projectId,
        string $name,
        ?string $description,
        ProjectType $projectType,
    ): Project {
        return $this->projects->update(
            organizationId: $organizationId,
            projectId: $projectId,
            name: $this->normalizeName($name),
            description: $this->normalizeDescription($description),
            projectType: $projectType,
        );
    }

    /**
     * Normalize and validate the project name for non-HTTP callers.
     */
    private function normalizeName(string $name): string
    {
        $normalizedName = trim($name);

        if (mb_strlen($normalizedName) < 2) {
            throw new InvalidArgumentException(
                'The project name must contain at least two characters.',
            );
        }

        if (mb_strlen($normalizedName) > 120) {
            throw new InvalidArgumentException(
                'The project name may not exceed 120 characters.',
            );
        }

        return $normalizedName;
    }

    /**
     * Normalize optional project description content.
     */
    private function normalizeDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        $normalizedDescription = trim($description);

        if ($normalizedDescription === '') {
            return null;
        }

        if (mb_strlen($normalizedDescription) > 5000) {
            throw new InvalidArgumentException(
                'The project description may not exceed 5000 characters.',
            );
        }

        return $normalizedDescription;
    }
}
