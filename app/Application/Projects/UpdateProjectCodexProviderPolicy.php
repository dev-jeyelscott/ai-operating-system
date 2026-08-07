<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Application\Audit\RecordAuditEvent;
use App\Application\Notifications\RecordSecurityConfigurationNotification;
use App\Application\Shared\Contracts\TransactionManager;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Integrations\IntegrationProvider;
use App\Domain\Projects\Configuration\CodexProviderPolicy;
use App\Domain\Projects\Configuration\ProjectConfigurationSchema;
use App\Domain\Projects\Configuration\ProviderPolicy;
use App\Domain\Projects\ProjectSetupStep;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ProjectSetupProgress;
use App\Models\ProviderCredential;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Updates the Codex sub-policy through the existing versioned project config.
 */
final readonly class UpdateProjectCodexProviderPolicy
{
    /**
     * Inject history, audit, notification, and transaction boundaries.
     */
    public function __construct(
        private RecordProjectConfigurationVersion $configurationVersions,
        private RecordAuditEvent $audit,
        private RecordSecurityConfigurationNotification $notifications,
        private TransactionManager $transactions,
    ) {}

    /**
     * Persist an explicit Codex policy and provider enablement atomically.
     *
     * @param  array<string, mixed>  $codexPolicyPayload
     */
    public function handle(
        int $actorUserId,
        int $organizationId,
        int $projectId,
        array $codexPolicyPayload,
        bool $fallbackEnabled,
        ?string $correlationId = null,
    ): ProjectConfiguration {
        $this->assertPositiveIdentifier($actorUserId, 'actor user');
        $this->assertPositiveIdentifier($organizationId, 'organization');
        $this->assertPositiveIdentifier($projectId, 'project');

        $candidateCodex = CodexProviderPolicy::fromArray($codexPolicyPayload);

        return $this->transactions->run(
            function () use (
                $actorUserId,
                $organizationId,
                $projectId,
                $candidateCodex,
                $fallbackEnabled,
                $correlationId,
            ): ProjectConfiguration {
                $project = Project::query()
                    ->forOrganization($organizationId)
                    ->whereKey($projectId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($project->isArchived()) {
                    throw ValidationException::withMessages([
                        'policy' => 'Archived projects cannot change provider policy.',
                    ]);
                }

                $configuration = ProjectConfiguration::query()
                    ->where('project_id', $project->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $current = ProviderPolicy::fromArray(
                    $configuration->provider_policy,
                );

                $allowed = array_values(array_filter(
                    $current->allowedProviderIds,
                    static fn (string $provider): bool => $provider !== IntegrationProvider::Codex->value,
                ));
                $fallback = array_values(array_filter(
                    $current->fallbackOrder,
                    static fn (string $provider): bool => $provider !== IntegrationProvider::Codex->value,
                ));

                if ($candidateCodex->enabled) {
                    $allowed[] = IntegrationProvider::Codex->value;

                    if ($fallbackEnabled) {
                        $fallback[] = IntegrationProvider::Codex->value;
                    }
                }

                if ($allowed === []) {
                    throw ValidationException::withMessages([
                        'policy' => 'At least one execution provider must remain allowed.',
                    ]);
                }

                if ($fallback === []) {
                    throw ValidationException::withMessages([
                        'fallback_enabled' => 'At least one provider must remain in fallback order.',
                    ]);
                }

                $candidate = ProviderPolicy::fromArray([
                    'allowed_provider_ids' => $allowed,
                    'fallback_order' => $fallback,
                    'codex' => $candidateCodex->toArray(),
                ]);

                $candidate->codex->assertWithinProjectPolicy(
                    projectDefaultReasoning: $configuration->default_reasoning,
                    projectBudgetLimitMinor: $configuration->budget_limit_minor,
                    projectAutomaticRetryLimit: $configuration->automatic_retry_limit,
                );

                if ($configuration->provider_policy === $candidate->toArray()) {
                    return $configuration;
                }

                $configuration->forceFill([
                    'schema_version' => ProjectConfigurationSchema::CURRENT_VERSION,
                    'provider_policy' => $candidate->toArray(),
                    'revision' => $configuration->revision + 1,
                ])->save();

                /*
                 * Any material Codex policy revision invalidates prior preflight.
                 * This prevents a check performed for one model/policy revision
                 * from authorizing a later, materially different configuration.
                 */
                ProviderCredential::query()
                    ->forOrganization($organizationId)
                    ->forProject($project->id)
                    ->where('provider', IntegrationProvider::Codex->value)
                    ->lockForUpdate()
                    ->update([
                        'last_connection_status' => null,
                        'last_connection_failure_code' => null,
                        'last_provider_request_id' => null,
                        'last_tested_by_user_id' => null,
                        'verified_credential_version' => null,
                        'last_tested_at' => null,
                    ]);

                $this->invalidateFinalReview($project->id);

                $this->configurationVersions->handle(
                    organizationId: $organizationId,
                    projectId: $project->id,
                    actorType: AuditActorType::User,
                    actorId: (string) $actorUserId,
                    changeReason: 'project_provider_policy.codex',
                    correlationId: $correlationId,
                );

                $event = $this->audit->record(
                    organizationId: $organizationId,
                    projectId: $project->id,
                    actorType: AuditActorType::User,
                    actorId: (string) $actorUserId,
                    eventType: AuditEventType::ProjectProviderPolicyUpdated,
                    subjectType: AuditSubjectType::Project,
                    subjectId: (string) $project->id,
                    correlationId: $correlationId,
                    metadata: [
                        'provider' => IntegrationProvider::Codex->value,
                        'enabled' => $candidate->codex->enabled,
                        'fallback_enabled' => in_array(
                            IntegrationProvider::Codex->value,
                            $candidate->fallbackOrder,
                            true,
                        ),
                        'model_identifier' => $candidate->codex->modelIdentifier,
                        'allowed_capabilities' => $candidate->codex->allowedCapabilities,
                        'reasoning' => $candidate->codex->reasoning,
                        'sandbox' => $candidate->codex->sandbox,
                        'network' => $candidate->codex->network,
                        'budget_limit_minor' => $candidate->codex->budgetLimitMinor,
                        'timeout_seconds' => $candidate->codex->timeoutSeconds,
                        'retry_limit' => $candidate->codex->retryLimit,
                        'configuration_revision' => $configuration->revision,
                    ],
                );

                $this->notifications->record(
                    source: $event,
                    recipientUserId: $actorUserId,
                    title: 'Codex provider policy updated',
                    message: $candidate->codex->enabled
                        ? 'Codex provider policy is enabled. Project start remains blocked until the current credential passes preflight.'
                        : 'Codex provider policy is disabled. Stored credentials and audit history were retained.',
                    actionUrl: route(
                        'organizations.projects.integrations.index',
                        [
                            'organization' => $project->organization,
                            'project' => $project,
                        ],
                    ),
                    data: [
                        'provider' => IntegrationProvider::Codex->value,
                        'enabled' => $candidate->codex->enabled,
                        'configuration_revision' => $configuration->revision,
                    ],
                );

                return $configuration->refresh();
            },
        );
    }

    /**
     * Invalidate final setup confirmation after a material policy change.
     */
    private function invalidateFinalReview(int $projectId): void
    {
        $progress = ProjectSetupProgress::query()
            ->where('project_id', $projectId)
            ->lockForUpdate()
            ->first();

        if ($progress === null || ! $progress->isComplete()) {
            return;
        }

        $progress->forceFill([
            'completed_steps' => array_values(array_filter(
                $progress->completed_steps,
                static fn (string $step): bool => $step !== ProjectSetupStep::Review->value,
            )),
            'completed_at' => null,
        ])->save();
    }

    /**
     * Reject invalid internal identifiers.
     */
    private function assertPositiveIdentifier(int $identifier, string $name): void
    {
        if ($identifier < 1) {
            throw new InvalidArgumentException(
                "The {$name} identifier must be positive.",
            );
        }
    }
}
