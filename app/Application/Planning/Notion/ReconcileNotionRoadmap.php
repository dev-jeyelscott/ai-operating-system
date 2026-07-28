<?php

declare(strict_types=1);

namespace App\Application\Planning\Notion;

use App\Application\Audit\RecordAuditEvent;
use App\Application\Integrations\Contracts\IntegrationCredentialCipher;
use App\Application\Integrations\Contracts\NotionPublicationClient;
use App\Application\Integrations\NotionPublicationException;
use App\Application\Shared\Contracts\TransactionManager;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Integrations\IntegrationCredentialSecret;
use App\Domain\Integrations\IntegrationProvider;
use App\Domain\Integrations\NotionConnectionStatus;
use App\Models\ExternalTicketMapping;
use App\Models\NotionReconciliationConflict;
use App\Models\ProjectIntegration;
use App\Models\ProviderCredential;
use App\Models\Roadmap;
use App\Models\RoadmapTask;
use Illuminate\Validation\ValidationException;

/** Detects external drift without changing approved roadmap content. */
final readonly class ReconcileNotionRoadmap
{
    public function __construct(
        private NotionPublicationClient $client,
        private IntegrationCredentialCipher $cipher,
        private NotionTicketMapper $mapper,
        private NotionTicketFingerprint $fingerprints,
        private TransactionManager $transactions,
        private RecordAuditEvent $audit,
    ) {}

    /** @return list<array{task_id:int,mapping_id:int|null,classification:string,evidence_fingerprint:string}> */
    public function handle(int $actorUserId, int $organizationId, Roadmap $roadmap, ?string $correlationId = null): array
    {
        $roadmap->loadMissing('tasks.externalTicketMappings');
        [$integration, $credential] = $this->connection($organizationId, $roadmap->project_id);
        $results = [];
        foreach ($roadmap->tasks as $task) {
            $mapping = $task->externalTicketMappings->firstWhere('provider', IntegrationProvider::Notion->value);
            if (! $mapping instanceof ExternalTicketMapping) {
                $results[] = $this->result($task, null, 'internal_pending_publication', hash('sha256', $task->stable_id));

                continue;
            }
            $results[] = $this->reconcileMapping($task, $mapping, $credential, $integration->data_source_id);
        }

        foreach ($results as $result) {
            $this->audit->record(
                organizationId: $organizationId,
                projectId: $roadmap->project_id,
                actorType: AuditActorType::User,
                actorId: (string) $actorUserId,
                eventType: AuditEventType::NotionTicketReconciled,
                subjectType: $result['mapping_id'] === null ? AuditSubjectType::Roadmap : AuditSubjectType::ExternalTicketMapping,
                subjectId: (string) ($result['mapping_id'] ?? $roadmap->id),
                correlationId: $correlationId,
                metadata: ['classification' => $result['classification'], 'evidence_fingerprint' => $result['evidence_fingerprint']],
            );
        }

        return $results;
    }

    /** @return array{task_id:int,mapping_id:int|null,classification:string,evidence_fingerprint:string} */
    private function reconcileMapping(RoadmapTask $task, ExternalTicketMapping $mapping, IntegrationCredentialSecret $credential, string $dataSourceId): array
    {
        try {
            $matches = $this->client->findPagesByTicketKey($credential, $dataSourceId, $mapping->external_key);
            if (count($matches) > 1) {
                return $this->persist($task, $mapping, 'duplicate_key', hash('sha256', $mapping->external_key));
            }
            if ($mapping->page_id === null || $matches === []) {
                return $this->persist($task, $mapping, 'missing_external_page', hash('sha256', $mapping->external_key));
            }
            $page = $this->client->retrievePage($credential, $mapping->page_id);
            if ($page->dataSourceId !== $dataSourceId) {
                return $this->persist($task, $mapping, 'schema_drift', hash('sha256', $page->dataSourceId));
            }
            $body = $this->client->retrievePageBody($credential, $mapping->page_id);
            $expected = $this->mapper->map($task, $mapping);
            $externalFingerprint = $this->fingerprints->from($page->properties, $body);
            $expectedFingerprint = $this->fingerprints->from($expected['properties'], $expected['body']);
            $classification = hash_equals($externalFingerprint, $expectedFingerprint)
                ? 'in_sync'
                : 'external_drift';
            if ($classification === 'in_sync' && ! hash_equals((string) $mapping->last_synchronized_fingerprint, $expected['fingerprint'])) {
                $classification = 'internal_pending_publication';
            }

            return $this->persist($task, $mapping, $classification, $externalFingerprint);
        } catch (NotionPublicationException $exception) {
            return $this->persist($task, $mapping, $exception->category === 'not_found' ? 'missing_external_page' : 'inaccessible', hash('sha256', $exception->category));
        }
    }

    /** @return array{ProjectIntegration, IntegrationCredentialSecret} */
    private function connection(int $organizationId, int $projectId): array
    {
        $integration = ProjectIntegration::query()->forOrganization($organizationId)->forProject($projectId)->where('provider', IntegrationProvider::Notion->value)->first();
        $credential = ProviderCredential::query()->forOrganization($organizationId)->forProject($projectId)->where('provider', IntegrationProvider::Notion->value)->first();
        if ($integration === null || $credential === null || $integration->connection_status !== NotionConnectionStatus::Connected || $integration->data_source_id === null || $integration->verified_credential_version !== $credential->version) {
            throw ValidationException::withMessages(['notion' => 'The verified Notion connection is not ready for reconciliation.']);
        }

        return [$integration, $this->cipher->decrypt($credential->secret_ciphertext)];
    }

    /** @return array{task_id:int,mapping_id:int|null,classification:string,evidence_fingerprint:string} */
    private function persist(RoadmapTask $task, ExternalTicketMapping $mapping, string $classification, string $fingerprint): array
    {
        $this->transactions->run(function () use ($task, $mapping, $classification, $fingerprint): void {
            $lockedMapping = ExternalTicketMapping::query()->lockForUpdate()->findOrFail($mapping->id);
            $lockedMapping->update(['reconciliation_state' => $classification, 'reconciliation_fingerprint' => $fingerprint, 'reconciled_at' => now()]);

            if (! in_array($classification, ['external_drift', 'duplicate_key', 'missing_external_page', 'inaccessible', 'schema_drift'], true)) {
                return;
            }

            $roadmap = $task->roadmap()->with('project')->firstOrFail();
            $conflict = NotionReconciliationConflict::query()->where('external_ticket_mapping_id', $lockedMapping->id)->where('state', 'open')->lockForUpdate()->latest('id')->first();
            $attributes = ['current_fingerprint' => $fingerprint, 'published_fingerprint' => $lockedMapping->last_synchronized_fingerprint, 'external_fingerprint' => $fingerprint];
            if ($conflict === null) {
                NotionReconciliationConflict::query()->create(['organization_id' => $roadmap->project->organization_id, 'project_id' => $roadmap->project_id, 'external_ticket_mapping_id' => $lockedMapping->id, 'state' => 'open', ...$attributes]);

                return;
            }

            $conflict->update($attributes);
        });

        return $this->result($task, $mapping, $classification, $fingerprint);
    }

    /** @return array{task_id:int,mapping_id:int|null,classification:string,evidence_fingerprint:string} */
    private function result(RoadmapTask $task, ?ExternalTicketMapping $mapping, string $classification, string $fingerprint): array
    {
        return ['task_id' => $task->id, 'mapping_id' => $mapping?->id, 'classification' => $classification, 'evidence_fingerprint' => $fingerprint];
    }
}
