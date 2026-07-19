<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Application\Audit\RecordAuditEvent;
use App\Application\Shared\Contracts\TransactionManager;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Projects\Configuration\ProjectPolicyConfiguration;
use App\Domain\Projects\Configuration\ValidationCommand;
use App\Domain\Projects\ProjectSetupStep;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ProjectSetupProgress;
use BackedEnum;
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
        bool $externalConfigurationChanged = false,
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
                $externalConfigurationChanged
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

                /*
                 * Compare the current casted values directly with the submitted
                 * candidate values before mutating the Eloquent model.
                 *
                 * This is required because:
                 *
                 * 1. PostgreSQL jsonb does not preserve object-key order.
                 * 2. Eloquent's generic dirty detection compares serialized raw
                 *    attributes rather than this domain's semantic values.
                 * 3. Calling fill() before reading casted values may interact with
                 *    Eloquent's cast cache and produce incorrect comparisons.
                 */
                /*
                * Some configuration belongs to another module-owned table. A successful
                * Notion target change still increments the global configuration revision.
                */
                $configurationChanged =
                    $externalConfigurationChanged
                    || $this->hasMaterialConfigurationChange(
                        configuration: $configuration,
                        candidateAttributes: $configurationAttributes,
                    );

                if ($configurationChanged) {
                    $configuration->fill($configurationAttributes);

                    $configuration->forceFill([
                        'revision' => $configuration->revision + 1,
                    ])->save();
                }

                /*
                 * Wizard progress advances after every valid submission,
                 * including a repeated idempotent submission.
                 */
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
     * Ensure projects created before the wizard implementation receive a
     * resumable setup-progress record.
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
     * Determine whether candidate configuration attributes materially differ
     * from the currently persisted configuration.
     *
     * Values are compared using a canonical domain representation:
     *
     * - backed enums are compared using their scalar values;
     * - associative JSON-object keys are sorted recursively;
     * - JSON-list order remains significant;
     * - scalar types are compared strictly.
     *
     * @param  array<string, mixed>  $candidateAttributes
     */
    private function hasMaterialConfigurationChange(
        ProjectConfiguration $configuration,
        array $candidateAttributes,
    ): bool {
        foreach ($candidateAttributes as $attribute => $candidateValue) {
            $currentValue = $configuration->getAttribute($attribute);

            if (
                $this->canonicalConfigurationValue($currentValue)
                !== $this->canonicalConfigurationValue($candidateValue)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert one configuration value into a stable comparable representation.
     *
     * Associative arrays represent JSON objects, so their keys are sorted.
     * Lists preserve their original order because ordering can be meaningful,
     * such as provider fallback order or command execution order.
     */
    private function canonicalConfigurationValue(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $canonical = [];

        foreach ($value as $key => $item) {
            $canonical[$key] =
                $this->canonicalConfigurationValue($item);
        }

        if (! array_is_list($canonical)) {
            ksort($canonical);
        }

        return $canonical;
    }

    /**
     * Convert a validated setup-step payload into configuration attributes.
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

            ProjectSetupStep::Integrations => [],

            ProjectSetupStep::Commands => $this->validationCommandAttributes(
                $payload,
            ),

            ProjectSetupStep::Policies => ProjectPolicyConfiguration::fromValidatedPayload(
                $payload,
            )->toPersistenceAttributes(),

            ProjectSetupStep::Review => [],
        };
    }

    /**
     * Mark the submitted step complete and advance the resumable wizard
     * position when the project has not already reached a later step.
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

        /*
         * Never move the resumable position backward when an already-completed
         * step is edited or submitted again.
         */
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

    /**
     * Convert validated command input into normalized persistence attributes.
     *
     * Re-validating through the domain value object protects internal callers that
     * bypass the HTTP Form Request, such as future jobs or console commands.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     build_command: string,
     *     test_command: string,
     *     lint_command: string,
     *     static_analysis_command: string,
     *     security_command: string
     * }
     */
    private function validationCommandAttributes(array $payload): array
    {
        return [
            'build_command' => ValidationCommand::from(
                (string) $payload['build_command'],
            )->value(),

            'test_command' => ValidationCommand::from(
                (string) $payload['test_command'],
            )->value(),

            'lint_command' => ValidationCommand::from(
                (string) $payload['lint_command'],
            )->value(),

            'static_analysis_command' => ValidationCommand::from(
                (string) $payload['static_analysis_command'],
            )->value(),

            'security_command' => ValidationCommand::from(
                (string) $payload['security_command'],
            )->value(),
        ];
    }
}
