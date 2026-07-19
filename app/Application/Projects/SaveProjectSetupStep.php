<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Application\Audit\RecordAuditEvent;
use App\Application\Shared\Contracts\TransactionManager;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Projects\ProjectSetupStep;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ProjectSetupProgress;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Persists one validated project setup step and advances wizard progress.
 */
final readonly class SaveProjectSetupStep
{
    /**
     * Inject audit recording and the application transaction boundary.
     */
    public function __construct(
        private RecordAuditEvent $audit,
        private TransactionManager $transactions,
    ) {}

    /**
     * Persist one server-validated wizard step atomically.
     *
     * Repeating the same request is idempotent: the configuration revision is
     * incremented only when a persisted configuration value materially changes.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(
        int $actorUserId,
        int $organizationId,
        int $projectId,
        ProjectSetupStep $step,
        array $payload,
        ?string $correlationId = null,
    ): ProjectSetupProgress {
        if ($actorUserId < 1) {
            throw new InvalidArgumentException(
                'The actor user identifier must be positive.',
            );
        }

        return $this->transactions->run(
            function () use (
                $actorUserId,
                $organizationId,
                $projectId,
                $step,
                $payload,
                $correlationId,
            ): ProjectSetupProgress {
                $project = Project::query()
                    ->forOrganization($organizationId)
                    ->whereKey($projectId)
                    ->firstOrFail();

                if ($project->isArchived()) {
                    throw ValidationException::withMessages([
                        'step' => 'Archived projects cannot be configured.',
                    ]);
                }

                /*
                 * Serialize competing setup submissions against the same
                 * authoritative project configuration record.
                 */
                $configuration = ProjectConfiguration::query()
                    ->where('project_id', $project->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->ensureProgressExists($project);

                $progress = ProjectSetupProgress::query()
                    ->where('project_id', $project->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertStepIsAvailable($progress, $step);

                $configurationAttributes =
                    $this->configurationAttributes($step, $payload);

                $configurationChanged = false;

                if ($configurationAttributes !== []) {
                    $configuration->fill($configurationAttributes);

                    $configurationChanged = $configuration->isDirty(
                        array_keys($configurationAttributes),
                    );

                    if ($configurationChanged) {
                        $configuration->forceFill([
                            'revision' => $configuration->revision + 1,
                        ])->save();
                    }
                }

                $progressChanged = $this->advanceProgress(
                    progress: $progress,
                    completedStep: $step,
                    configurationChanged: $configurationChanged,
                );

                if ($configurationChanged || $progressChanged) {
                    $this->audit->record(
                        organizationId: $organizationId,
                        projectId: $project->id,
                        actorType: AuditActorType::User,
                        actorId: (string) $actorUserId,
                        eventType: AuditEventType::ProjectSetupUpdated,
                        subjectType: AuditSubjectType::Project,
                        subjectId: (string) $project->id,
                        correlationId: $correlationId,
                        metadata: [
                            'step' => $step->value,
                            'configuration_revision' => $configuration->revision,
                            'configuration_changed' => $configurationChanged,
                            'current_step' => $progress->current_step->value,
                            'setup_complete' => $progress->isComplete(),
                        ],
                    );
                }

                return $progress->refresh();
            },
        );
    }

    /**
     * Ensure old projects created before AIOS-022 receive a progress row.
     */
    private function ensureProgressExists(Project $project): void
    {
        ProjectSetupProgress::query()->insertOrIgnore([
            'project_id' => $project->id,
            'current_step' => ProjectSetupStep::Details->value,
            'completed_steps' => '[]',
            'completed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Prevent requests from completing a step before its predecessor.
     */
    private function assertStepIsAvailable(
        ProjectSetupProgress $progress,
        ProjectSetupStep $step,
    ): void {
        $previous = $step->previous();

        if (
            $previous !== null
            && ! $progress->hasCompleted($previous)
        ) {
            throw ValidationException::withMessages([
                'step' => sprintf(
                    'Complete "%s" before continuing to "%s".',
                    $previous->label(),
                    $step->label(),
                ),
            ]);
        }
    }

    /**
     * Map the validated step payload to AIOS-021 configuration columns.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function configurationAttributes(
        ProjectSetupStep $step,
        array $payload,
    ): array {
        return match ($step) {
            ProjectSetupStep::Details => [
                'technology_stack' => $payload['technology_stack'],
            ],

            ProjectSetupStep::Repository => [
                'repository_provider' => $payload['repository_provider'],
                'repository_url' => $payload['repository_url'],
                'default_branch' => $payload['default_branch'],
                'integration_branch' => $payload['integration_branch'],
            ],

            ProjectSetupStep::Commands => [
                'build_command' => $payload['build_command'],
                'test_command' => $payload['test_command'],
                'lint_command' => $payload['lint_command'],
                'static_analysis_command' => $payload['static_analysis_command'],
                'security_command' => $payload['security_command'],
            ],

            ProjectSetupStep::Policies => [
                'default_reasoning' => $payload['default_reasoning'],
                'provider_policy' => $payload['provider_policy'],
                'budget_limit_minor' => $payload['budget_limit_minor'],
                'budget_currency' => $payload['budget_currency'],
                'automatic_retry_limit' => $payload['automatic_retry_limit'],
                'autonomy_level' => $payload['autonomy_level'],
                'approval_policy' => $payload['approval_policy'],
                'notification_policy' => $payload['notification_policy'],
            ],

            ProjectSetupStep::Review => [],
        };
    }

    /**
     * Mark the current step complete and advance the resumable position.
     */
    private function advanceProgress(
        ProjectSetupProgress $progress,
        ProjectSetupStep $completedStep,
        bool $configurationChanged,
    ): bool {
        $completedSteps = $progress->completed_steps;

        if (! in_array($completedStep->value, $completedSteps, true)) {
            $completedSteps[] = $completedStep->value;
        }

        /*
         * Editing configuration after final confirmation invalidates only the
         * final review, while preserving completion of the edited sections.
         */
        if (
            $completedStep !== ProjectSetupStep::Review
            && $configurationChanged
            && $progress->isComplete()
        ) {
            $completedSteps = array_values(
                array_filter(
                    $completedSteps,
                    static fn (string $step): bool => $step !== ProjectSetupStep::Review->value,
                ),
            );

            $progress->completed_at = null;
        }

        $nextStep = $completedStep->next()
            ?? ProjectSetupStep::Review;

        if (
            $nextStep->position()
            > $progress->current_step->position()
        ) {
            $progress->current_step = $nextStep;
        }

        $progress->completed_steps = array_values(
            array_unique($completedSteps),
        );

        if (
            $completedStep === ProjectSetupStep::Review
            && $progress->completed_at === null
        ) {
            $progress->completed_at = now();
        }

        $changed = $progress->isDirty();

        if ($changed) {
            $progress->save();
        }

        return $changed;
    }
}
