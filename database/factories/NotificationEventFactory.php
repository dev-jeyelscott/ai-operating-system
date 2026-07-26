<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NotificationEvent;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NotificationEvent>
 */
final class NotificationEventFactory extends Factory
{
    /**
     * Define one valid project-scoped notification event.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'source_event_id' => (string) Str::ulid(),
            'event_name' => 'human_decision.required',
            'title' => 'Human decision required',
            'message' => 'A workflow requires an authorized human decision.',
            'action_url' => null,
            'data' => [
                'requires_action' => true,
                'decision_type' => 'workflow_approval',
            ],
            'correlation_id' => (string) Str::ulid(),
            'execution_id' => null,
            'occurred_at' => now(),
        ];
    }

    /**
     * Derive organization ownership from the selected project.
     */
    public function configure(): static
    {
        return $this->afterMaking(
            function (NotificationEvent $notificationEvent): void {
                if ($notificationEvent->project_id === null) {
                    return;
                }

                /** @var Project $project */
                $project = Project::query()
                    ->findOrFail($notificationEvent->project_id);

                $notificationEvent->forceFill([
                    'organization_id' => $project->organization_id,
                ]);
            },
        );
    }

    /**
     * Bind the notification event to one explicit project.
     */
    public function forProject(Project $project): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
        ]);
    }

    /**
     * Create an organization-wide notification with no project.
     */
    public function organizationWide(
        Organization $organization,
    ): static {
        return $this->state(fn (): array => [
            'organization_id' => $organization->id,
            'project_id' => null,
        ]);
    }
}
