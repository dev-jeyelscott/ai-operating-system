<?php

declare(strict_types=1);

namespace App\Application\Projects;

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
     * Apply validated metadata to the project.
     */
    public function handle(
        Project $project,
        string $name,
        ?string $description,
        ProjectType $projectType,
    ): Project {
        $project->fill([
            'name' => $this->normalizeName($name),
            'description' => $this->normalizeDescription($description),
            'project_type' => $projectType,
        ])->save();

        /*
         * The slug intentionally remains stable when the display name changes.
         */
        return $project->refresh();
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
