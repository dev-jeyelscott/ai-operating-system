<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Domain\Projects\ProjectType;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates a new draft project inside an authorized organization.
 */
final readonly class CreateProject
{
    /**
     * Create the project while preserving organization ownership.
     */
    public function handle(
        Organization $organization,
        string $name,
        ?string $description,
        ProjectType $projectType,
    ): Project {
        $normalizedName = $this->normalizeName($name);
        $normalizedDescription = $this->normalizeDescription($description);

        $project = new Project([
            'name' => $normalizedName,
            'slug' => $this->generateSlug($normalizedName),
            'description' => $normalizedDescription,
            'project_type' => $projectType,
        ]);

        /*
         * Saving through the relationship assigns organization_id without
         * making ownership mass assignable.
         */
        $organization->projects()->save($project);

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

    /**
     * Generate a stable globally unique route slug.
     */
    private function generateSlug(string $name): string
    {
        $prefix = Str::slug($name);

        if ($prefix === '') {
            $prefix = 'project';
        }

        return sprintf(
            '%s-%s',
            Str::limit($prefix, 150, ''),
            strtolower((string) Str::ulid()),
        );
    }
}
