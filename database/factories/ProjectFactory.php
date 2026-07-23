<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Projects\ProjectStatus;
use App\Domain\Projects\ProjectType;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
final class ProjectFactory extends Factory
{
    /** @var class-string<Project> */
    protected $model = Project::class;

    /**
     * Define a valid draft project owned by an organization.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company().' Project';

        return [
            'organization_id' => Organization::factory(),
            'name' => $name,
            'slug' => sprintf(
                '%s-%s',
                Str::slug($name),
                strtolower((string) Str::ulid()),
            ),
            'description' => fake()->optional()->sentence(),
            'project_type' => ProjectType::WebApplication,
            'status' => ProjectStatus::Draft,
            'status_changed_at' => now(),
        ];
    }

    /**
     * Create a project in the supplied lifecycle state for focused tests.
     */
    public function status(ProjectStatus $status): static
    {
        return $this->state(
            fn (): array => [
                'status' => $status,
                'status_changed_at' => now(),
            ],
        );
    }
}
