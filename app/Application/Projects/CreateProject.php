<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Application\Projects\Contracts\ProjectRepository;
use App\Domain\Projects\ProjectType;
use App\Models\Project;
use InvalidArgumentException;

/**
 * Creates a new draft project inside an authorized organization.
 */
final readonly class CreateProject
{
    /**
     * Inject the tenant-safe project persistence contract.
     */
    public function __construct(
        private ProjectRepository $projects,
    ) {}

    /**
     * Create the project while preserving organization ownership.
     */
    public function handle(
        int $organizationId,
        string $name,
        ?string $description,
        ProjectType $projectType,
    ): Project {
        return $this->projects->create(
            organizationId: $organizationId,
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
