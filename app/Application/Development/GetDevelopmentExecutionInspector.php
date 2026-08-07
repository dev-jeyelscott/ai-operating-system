<?php

declare(strict_types=1);

namespace App\Application\Development;

use App\Models\Artifact;
use App\Models\AuditEvent;
use App\Models\Evidence;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\OutboxMessage;
use App\Models\Project;
use App\Models\TicketExecutionLease;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

final class GetDevelopmentExecutionInspector
{
    /** @return array<string, mixed> */
    public function handle(int $organizationId, int $projectId, string $executionId): array
    {
        $project = Project::query()
            ->forOrganization($organizationId)
            ->whereKey($projectId)
            ->firstOrFail();
        $execution = Execution::query()
            ->forProject($project->id)
            ->whereKey($executionId)
            ->whereIn('capability', ['development', 'development.execute'])
            ->with([
                'attempts',
                'projectContextSnapshot',
            ])
            ->firstOrFail();
        $lease = TicketExecutionLease::query()
            ->where('project_id', $project->id)
            ->where('execution_id', $execution->id)
            ->with('ticket:id,stable_id,title,objective,status,actual_state')
            ->first();
        $artifacts = Artifact::query()
            ->forProject($project->id)
            ->forExecution($execution->id)
            ->with('evidence')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $audit = AuditEvent::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $project->id)
            ->where('execution_id', $execution->id)
            ->orderBy('occurred_at')
            ->orderBy('sequence')
            ->get();
        $events = OutboxMessage::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $project->id)
            ->where('execution_id', $execution->id)
            ->orderBy('occurred_at')
            ->orderBy('sequence')
            ->get();
        $serializedArtifacts = array_values($artifacts
            ->map(fn (Artifact $artifact): array => $this->serializeArtifact($artifact))
            ->all());
        $artifactTypes = $artifacts->pluck('artifact_type')->all();
        $context = $execution->projectContextSnapshot;
        $latestAttempt = $execution->attempts->last();

        return [
            'execution' => [
                'id' => $execution->id,
                'capability' => $execution->capability,
                'status' => $execution->status->value,
                'terminal' => $execution->status->isTerminal(),
                'provider' => $latestAttempt instanceof ExecutionAttempt ? $latestAttempt->execution_provider : 'simulation',
                'requestedReasoning' => $execution->requested_reasoning_level->value,
                'attemptCount' => $execution->attempt_count,
                'retryLimit' => $execution->retry_limit,
                'nextAttemptAt' => $execution->next_attempt_at?->toISOString(),
                'cancelRequestedAt' => $execution->cancel_requested_at?->toISOString(),
                'cancelledAt' => $execution->cancelled_at?->toISOString(),
                'cancellationReason' => $execution->cancellation_reason,
                'startedAt' => $execution->started_at?->toISOString(),
                'finishedAt' => $execution->finished_at?->toISOString(),
            ],
            'ticket' => $lease === null ? null : [
                'id' => $lease->ticket->stable_id,
                'title' => $lease->ticket->title,
                'objective' => $lease->ticket->objective,
                'status' => $lease->ticket->status->value,
                'actualState' => $lease->ticket->actual_state->value,
            ],
            'simulation' => [
                'simulated' => true,
                'verified' => false,
                'evidenceStillRequired' => true,
            ],
            'contextSnapshot' => $context === null ? null : [
                'id' => $context->id,
                'configurationRevision' => $context->configuration_revision,
                'identitySchemaVersion' => $context->identity_schema_version,
                'approvedDocumentSetFingerprint' => $context->approved_document_set_fingerprint,
                'approvedDocumentCount' => count($context->approved_document_versions),
                'createdAt' => $context->created_at->toISOString(),
            ],
            'attempts' => $execution->attempts
                ->map(fn (ExecutionAttempt $attempt): array => $this->serializeAttempt($attempt))
                ->values()
                ->all(),
            'artifacts' => $serializedArtifacts,
            'plan' => $this->artifactDetails($serializedArtifacts, 'implementation_plan', 'plan'),
            'changedFiles' => $this->artifactDetails($serializedArtifacts, 'changed_file_manifest', 'files'),
            'diffSummary' => $this->diffSummary($serializedArtifacts),
            'validations' => [
                ...$this->artifactDetails($serializedArtifacts, 'validation_result', 'validations'),
                ...$this->artifactDetails($serializedArtifacts, 'validation_failure', 'validations'),
            ],
            'repository' => [
                'branch' => $this->repositoryArtifact($serializedArtifacts, 'synthetic_branch'),
                'commit' => $this->repositoryArtifact($serializedArtifacts, 'synthetic_commit'),
                'push' => $this->repositoryArtifact($serializedArtifacts, 'synthetic_push'),
                'pullRequest' => $this->repositoryArtifact($serializedArtifacts, 'synthetic_pull_request'),
            ],
            'assumptions' => $artifacts->pluck('assumptions')->flatten()
                ->filter(static fn (mixed $assumption): bool => is_string($assumption))
                ->unique()->values()->all(),
            'confidence' => $artifacts->pluck('confidence')->filter()->first(),
            'risks' => ['Simulation did not inspect or modify a real repository.'],
            'evidenceGaps' => $artifacts->contains('evidence_still_required', true)
                ? ['Real repository, command, CI, review, and merge evidence remain required.'] : [],
            'missingArtifacts' => array_values(array_diff([
                'implementation_plan',
                'changed_file_manifest',
                'validation_result',
                'synthetic_branch',
                'synthetic_commit',
                'synthetic_push',
                'synthetic_pull_request',
            ], $artifactTypes)),
            'lease' => $this->serializeLease($lease, $execution),
            'retry' => [
                'scheduled' => $execution->status->value === 'retry_scheduled',
                'attemptCount' => $execution->attempt_count,
                'retryLimit' => $execution->retry_limit,
                'nextAttemptAt' => $execution->next_attempt_at?->toISOString(),
            ],
            'error' => $this->latestError($execution->attempts),
            'auditTimeline' => $audit->map(static fn (AuditEvent $event): array => [
                'sequence' => $event->sequence,
                'type' => $event->event_type->value,
                'attemptId' => is_int($event->metadata['attempt_id'] ?? null) ? $event->metadata['attempt_id'] : null,
                'leaseId' => is_string($event->metadata['lease_id'] ?? null) ? $event->metadata['lease_id'] : null,
                'occurredAt' => $event->occurred_at->toISOString(),
            ])->values()->all(),
            'lifecycleEvents' => $events->map(static fn (OutboxMessage $event): array => [
                'sequence' => $event->sequence,
                'eventId' => $event->event_id,
                'name' => $event->event_name,
                'schemaVersion' => $event->schema_version,
                'occurredAt' => $event->occurred_at->toISOString(),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeAttempt(ExecutionAttempt $attempt): array
    {
        return [
            'id' => $attempt->id,
            'number' => $attempt->attempt_number,
            'status' => $attempt->status->value,
            'provider' => $attempt->execution_provider,
            'modelIdentifier' => $attempt->model_identifier,
            'requestedReasoning' => $attempt->requested_reasoning_level->value,
            'effectiveReasoning' => $attempt->effective_reasoning_level->value,
            'effectiveCapability' => $attempt->effective_capability,
            'protocolVersion' => $attempt->provider_protocol_version,
            'sandboxProfile' => $attempt->provider_sandbox_profile,
            'providerSelectionSource' => $attempt
                ->provider_selection_source,
            'simulationScenario' => $attempt->simulation_scenario,
            'reasoningSource' => $attempt->reasoning_resolution_source,
            'reasoningEscalationReason' => $attempt->reasoning_escalation_reason,
            'simulationMode' => $attempt->simulation_mode,
            'simulationSeed' => $attempt->simulation_seed,
            'actualState' => $attempt->actual_state,
            'confidence' => $attempt->confidence,
            'error' => $attempt->error_code === null ? null : [
                'code' => $attempt->error_code,
                'message' => $attempt->error_message,
                'retryable' => $attempt->retryable,
                'retryDelaySeconds' => $attempt->retry_delay_seconds,
            ],
            'deadlineAt' => $attempt->deadline_at?->toISOString(),
            'heartbeatAt' => $attempt->heartbeat_at?->toISOString(),
            'startedAt' => $attempt->started_at?->toISOString(),
            'finishedAt' => $attempt->finished_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeArtifact(Artifact $artifact): array
    {
        return [
            'id' => $artifact->id,
            'type' => $artifact->artifact_type,
            'name' => $artifact->name,
            'provider' => $artifact->execution_provider,
            'reference' => $artifact->external_reference,
            'simulationMode' => $artifact->simulation_mode,
            'simulationSeed' => $artifact->simulation_seed,
            'assumptions' => $artifact->assumptions,
            'confidence' => $artifact->confidence,
            'actualState' => $artifact->actual_state,
            'evidenceStillRequired' => $artifact->evidence_still_required,
            'details' => $this->safeArtifactDetails($artifact),
            'evidence' => $artifact->evidence->map(fn (Evidence $evidence): array => $this->serializeEvidence($evidence))->values()->all(),
            'createdAt' => $artifact->created_at->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function safeArtifactDetails(Artifact $artifact): array
    {
        return match ($artifact->artifact_type) {
            'implementation_plan' => ['plan' => $this->stringList(Arr::get($artifact->metadata, 'plan'))],
            'changed_file_manifest' => ['files' => $this->structuredList(Arr::get($artifact->metadata, 'files'), ['path', 'change_type', 'summary'])],
            'validation_result', 'validation_failure' => ['validations' => $this->structuredList(Arr::get($artifact->metadata, 'validations'), ['command', 'status', 'summary'])],
            'synthetic_branch', 'synthetic_commit', 'synthetic_push', 'synthetic_pull_request' => Arr::only($artifact->metadata, [
                'kind',
                'identifier',
                'reference',
                'target_branch',
                'synthetic',
                'evidence_still_required',
            ]),
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function serializeEvidence(Evidence $evidence): array
    {
        return [
            'id' => $evidence->id,
            'classification' => $evidence->classification->value,
            'type' => $evidence->evidence_type,
            'provider' => $evidence->provider,
            'sourceReference' => $evidence->source_reference,
            'commitSha' => $evidence->commit_sha,
            'claims' => $evidence->claims,
            'confidence' => $evidence->confidence,
            'verified' => $evidence->isVerified(),
            'createdAt' => $evidence->created_at->toISOString(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $artifacts
     * @return list<mixed>
     */
    private function artifactDetails(array $artifacts, string $type, string $key): array
    {
        foreach ($artifacts as $artifact) {
            if ($artifact['type'] === $type) {
                $value = $artifact['details'][$key] ?? [];

                return is_array($value) && array_is_list($value) ? $value : [];
            }
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $artifacts
     * @return array<string, mixed>|null
     */
    private function repositoryArtifact(array $artifacts, string $type): ?array
    {
        foreach ($artifacts as $artifact) {
            if ($artifact['type'] === $type) {
                return [
                    'name' => $artifact['name'],
                    'reference' => $artifact['reference'],
                    ...$artifact['details'],
                ];
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $artifacts */
    private function diffSummary(array $artifacts): ?string
    {
        $files = $this->artifactDetails($artifacts, 'changed_file_manifest', 'files');

        return $files === []
            ? null
            : sprintf('Synthetic manifest contains %d changed file(s); no repository diff was produced.', count($files));
    }

    /** @return array<string, mixed>|null */
    private function serializeLease(?TicketExecutionLease $lease, Execution $execution): ?array
    {
        if ($lease === null) {
            return null;
        }

        $active = $lease->isActive();
        $expired = $lease->expires_at->isPast();

        return [
            'id' => $lease->id,
            'owner' => $lease->owner,
            'active' => $active,
            'expired' => $expired,
            'expiredButExecutionLive' => $active && $expired && ! $execution->status->isTerminal(),
            'expiresAt' => $lease->expires_at->toISOString(),
            'heartbeatAt' => $lease->heartbeat_at->toISOString(),
            'releasedAt' => $lease->released_at?->toISOString(),
            'releaseReason' => $lease->release_reason?->value,
            'recoveryState' => $active ? ($expired ? 'awaiting_liveness_check' : 'not_due') : 'released',
        ];
    }

    /**
     * @param  Collection<int, ExecutionAttempt>  $attempts
     * @return array<string, mixed>|null
     */
    private function latestError(Collection $attempts): ?array
    {
        $attempt = $attempts->reverse()->first(static fn (ExecutionAttempt $candidate): bool => $candidate->error_code !== null);

        return $attempt instanceof ExecutionAttempt ? [
            'attemptNumber' => $attempt->attempt_number,
            'code' => $attempt->error_code,
            'message' => $attempt->error_message,
            'retryable' => $attempt->retryable,
            'retryDelaySeconds' => $attempt->retry_delay_seconds,
        ] : null;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return is_array($value) && array_is_list($value)
            ? array_values(array_filter($value, is_string(...))) : [];
    }

    /**
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function structuredList(mixed $value, array $keys): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return array_map(
            static fn (mixed $item): array => is_array($item) ? Arr::only($item, $keys) : [],
            $value,
        );
    }
}
