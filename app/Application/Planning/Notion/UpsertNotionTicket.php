<?php

declare(strict_types=1);

namespace App\Application\Planning\Notion;

use App\Application\Audit\RecordAuditEvent;
use App\Application\Integrations\Contracts\IntegrationCredentialCipher;
use App\Application\Integrations\Contracts\NotionPublicationClient;
use App\Application\Integrations\NotionPublicationException;
use App\Application\Planning\RoadmapEligibility;
use App\Application\Shared\Contracts\TransactionManager;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Integrations\IntegrationCredentialSecret;
use App\Domain\Integrations\IntegrationProvider;
use App\Domain\Integrations\NotionConnectionStatus;
use App\Models\ExternalTicketMapping;
use App\Models\ProjectIntegration;
use App\Models\ProviderCredential;
use App\Models\RoadmapTask;
use Illuminate\Validation\ValidationException;

/** Performs one task-scoped, lock-safe Notion upsert. */
final readonly class UpsertNotionTicket
{
    public function __construct(
        private AllocateExternalTicketMapping $allocateMapping,
        private NotionTicketMapper $mapper,
        private NotionTicketSchema $schema,
        private NotionPublicationClient $client,
        private IntegrationCredentialCipher $cipher,
        private RoadmapEligibility $eligibility,
        private TransactionManager $transactions,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(int $actorUserId, int $organizationId, RoadmapTask $task, ?string $correlationId = null): NotionTicketUpsertResult
    {
        $task->loadMissing('roadmap');
        if (! $this->eligibility->allowsPublicationOrDevelopment($task->roadmap)) {
            throw ValidationException::withMessages(['roadmap' => 'Only the current approved and eligible roadmap may be published.']);
        }
        $mapping = $this->allocateMapping->handle($task);
        if ($this->publicationIsBlockedByReconciliation($mapping)) {
            return new NotionTicketUpsertResult(
                'blocked',
                $mapping->id,
                message: 'Resolve the Notion reconciliation conflict before publishing this ticket.',
            );
        }
        $payload = $this->mapper->map($task, $mapping);
        if ($mapping->state === 'synchronized' && hash_equals((string) $mapping->last_synchronized_fingerprint, $payload['fingerprint'])) {
            return new NotionTicketUpsertResult('skipped', $mapping->id, $mapping->page_url);
        }

        [$integration, $credential] = $this->publicationConnection($organizationId, $task->roadmap->project_id);
        $source = $this->client->retrieveDataSource($credential, $integration->data_source_id);
        if (! $this->schema->isReady($source)) {
            $this->fail($mapping->id, 'schema_incompatible', false, null);
            throw ValidationException::withMessages(['notion' => 'The selected Notion data source does not satisfy the publication schema.']);
        }

        $mappingId = $mapping->id;
        $mapping = $this->markAttempt($mappingId);
        if ($mapping === null) {
            return new NotionTicketUpsertResult('blocked', $mappingId, message: 'This task is already being published.');
        }
        try {
            $page = $mapping->page_id === null
                ? null
                : $this->client->retrievePage($credential, $mapping->page_id);
            if ($page === null) {
                $matches = $this->client->findPagesByTicketKey($credential, $integration->data_source_id, $mapping->external_key);
                if (count($matches) > 1) {
                    $this->fail($mapping->id, 'duplicate_remote_key', false, null, 'conflicted');

                    return new NotionTicketUpsertResult('conflicted', $mapping->id, message: 'Multiple Notion pages use this ticket key.');
                }
                $page = $matches[0] ?? null;
            }
            if ($page !== null && $page->dataSourceId !== $integration->data_source_id) {
                $this->fail($mapping->id, 'mismatched_parent', false, $page->providerRequestId, 'conflicted');

                return new NotionTicketUpsertResult('conflicted', $mapping->id, message: 'The mapped page belongs to another data source.');
            }
            $outcome = $page === null ? 'created' : 'updated';
            $page = $page === null
                ? $this->client->createPage($credential, $integration->data_source_id, $payload['properties'], $payload['body'])
                : $this->client->updatePage($credential, $page->id, $payload['properties'], $payload['body']);
            if ($page->dataSourceId !== $integration->data_source_id || ! $this->pageHasKey($page->properties, $mapping->external_key)) {
                $this->fail($mapping->id, 'mismatched_response', false, $page->providerRequestId, 'conflicted');

                return new NotionTicketUpsertResult('conflicted', $mapping->id, message: 'Notion returned a page with a different parent or ticket key.');
            }
            $saved = $this->succeed($mapping->id, $page->id, $page->url, $payload['fingerprint'], $page->providerRequestId);
            $this->audit->record($organizationId, $task->roadmap->project_id, AuditActorType::User, (string) $actorUserId, AuditEventType::NotionTicketPublished, AuditSubjectType::ExternalTicketMapping, (string) $saved->id, $correlationId, ['outcome' => $outcome, 'external_key' => $saved->external_key, 'provider_request_id' => $page->providerRequestId]);

            return new NotionTicketUpsertResult($outcome, $saved->id, $saved->page_url);
        } catch (NotionPublicationException $exception) {
            $this->fail($mapping->id, $exception->category, $exception->retryable, $exception->providerRequestId);
            $this->audit->record($organizationId, $task->roadmap->project_id, AuditActorType::User, (string) $actorUserId, AuditEventType::NotionTicketPublicationFailed, AuditSubjectType::ExternalTicketMapping, (string) $mapping->id, $correlationId, ['category' => $exception->category, 'retryable' => $exception->retryable, 'provider_request_id' => $exception->providerRequestId]);

            return new NotionTicketUpsertResult('failed', $mapping->id, message: 'Notion publication failed.');
        }
    }

    /** @return array{ProjectIntegration, IntegrationCredentialSecret} */
    private function publicationConnection(int $organizationId, int $projectId): array
    {
        $integration = ProjectIntegration::query()->forOrganization($organizationId)->forProject($projectId)->where('provider', IntegrationProvider::Notion->value)->first();
        $credential = ProviderCredential::query()->forOrganization($organizationId)->forProject($projectId)->where('provider', IntegrationProvider::Notion->value)->first();
        if ($integration === null || $credential === null || $integration->connection_status !== NotionConnectionStatus::Connected || $integration->data_source_id === null || $integration->verified_credential_version !== $credential->version) {
            throw ValidationException::withMessages(['notion' => 'The verified Notion connection is not ready for publication.']);
        }

        return [$integration, $this->cipher->decrypt($credential->secret_ciphertext)];
    }

    private function markAttempt(int $mappingId): ?ExternalTicketMapping
    {
        return $this->transactions->run(function () use ($mappingId): ?ExternalTicketMapping {
            $mapping = ExternalTicketMapping::query()->lockForUpdate()->findOrFail($mappingId);
            if ($mapping->state === 'publishing') {
                return null;
            }
            $mapping->update(['state' => 'publishing', 'attempt_count' => $mapping->attempt_count + 1, 'last_attempted_at' => now(), 'failure_metadata' => null]);

            return $mapping->fresh();
        });
    }

    private function succeed(int $mappingId, string $pageId, string $pageUrl, string $fingerprint, ?string $requestId): ExternalTicketMapping
    {
        return $this->transactions->run(function () use ($mappingId, $pageId, $pageUrl, $fingerprint, $requestId): ExternalTicketMapping {
            $mapping = ExternalTicketMapping::query()->lockForUpdate()->findOrFail($mappingId);
            $mapping->update(['page_id' => $pageId, 'page_url' => $pageUrl, 'last_synchronized_fingerprint' => $fingerprint, 'state' => 'synchronized', 'failure_metadata' => null, 'last_provider_request_id' => $requestId, 'last_published_at' => now(), 'reconciliation_state' => 'in_sync', 'reconciliation_fingerprint' => $fingerprint, 'reconciled_at' => now()]);

            return $mapping->fresh();
        });
    }

    private function fail(int $mappingId, string $category, bool $retryable, ?string $requestId, string $state = 'failed'): void
    {
        $this->transactions->run(function () use ($mappingId, $category, $retryable, $requestId, $state): void {
            ExternalTicketMapping::query()->lockForUpdate()->findOrFail($mappingId)->update(['state' => $state, 'last_provider_request_id' => $requestId, 'failure_metadata' => ['category' => $category, 'retryable' => $retryable]]);
        });
    }

    /** @param array<string, mixed> $properties */
    private function pageHasKey(array $properties, string $key): bool
    {
        $richText = $properties['Ticket ID']['rich_text'] ?? null;
        if (! is_array($richText)) {
            return false;
        }

        return collect($richText)->contains(fn (mixed $item): bool => is_array($item) && (($item['plain_text'] ?? null) === $key || ($item['text']['content'] ?? null) === $key));
    }

    private function publicationIsBlockedByReconciliation(ExternalTicketMapping $mapping): bool
    {
        return in_array($mapping->reconciliation_state, [
            'external_drift',
            'duplicate_key',
            'missing_external_page',
            'inaccessible',
            'schema_drift',
            'deferred',
        ], true);
    }
}
