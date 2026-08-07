<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\Approvals\Commands\RequestApproval;
use App\Application\Executions\Data\ProviderSelection;
use App\Application\Planning\Data\PlanningExecutionRequest;
use App\Application\Planning\Data\PlanningSourceReference;
use App\Application\Planning\PersistRoadmap;
use App\Application\Shared\Commands\CommandBus;
use App\Domain\Approvals\ApprovalType;
use App\Domain\Audit\AuditActorType;
use App\Domain\Identity\OrganizationRole;
use App\Domain\Projects\Configuration\ReasoningLevel;
use App\Domain\Projects\ProjectStatus;
use App\Infrastructure\AgentProviders\SimulationPlanningProvider;
use App\Models\Approval;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Execution;
use App\Models\ExternalTicketMapping;
use App\Models\NotionReconciliationConflict;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\ProjectConfigurationVersion;
use App\Models\ProjectContextSnapshot;
use App\Models\Roadmap;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RoadmapE2ESeeder extends Seeder
{
    private const USER_EMAIL = 'roadmap-owner@example.test';

    private const ORGANIZATION_SLUG = 'roadmap-browser';

    /**
     * Route actions that must always receive a fresh roadmap fixture.
     *
     * @var list<string>
     */
    private const ACTIONS = [
        'approve',
        'edit',
        'reject',
        'regenerate',
        'conflict',
    ];

    /**
     * Prepare a repeatable roadmap browser fixture without deleting history.
     */
    public function run(): void
    {
        $this->call(ProjectDeliveryWorkflowSeeder::class);

        DB::transaction(function (): void {
            $user = $this->prepareUser();
            $organization = $this->prepareOrganization($user);

            $this->retireExistingFixtureProjects($organization);

            foreach (['approve', 'edit', 'reject', 'regenerate'] as $action) {
                $this->createAwaitingRoadmap(
                    organization: $organization,
                    user: $user,
                    action: $action,
                );
            }

            $roadmap = $this->createAwaitingRoadmap(
                organization: $organization,
                user: $user,
                action: 'conflict',
            );

            $this->createNotionConflict($organization, $roadmap);
        }, attempts: 3);
    }

    /**
     * Create or reset the deterministic browser-test user.
     */
    private function prepareUser(): User
    {
        $user = User::query()->updateOrCreate(
            [
                'email' => self::USER_EMAIL,
            ],
            [
                'name' => 'Roadmap Browser Owner',
                'password' => 'password',
            ],
        );

        /*
         * email_verified_at is deliberately guarded from mass assignment.
         * The fixture owns this deterministic testing-only state.
         */
        $user->forceFill([
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }

    /**
     * Create or reset the deterministic organization and owner membership.
     */
    private function prepareOrganization(User $user): Organization
    {
        $organization = Organization::query()->updateOrCreate(
            [
                'slug' => self::ORGANIZATION_SLUG,
            ],
            [
                'name' => 'Roadmap Browser Organization',
            ],
        );

        OrganizationMembership::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
            ],
            [
                'role' => OrganizationRole::Owner,
            ],
        );

        return $organization;
    }

    /**
     * Remove stale route collisions without deleting retained project history.
     */
    private function retireExistingFixtureProjects(
        Organization $organization,
    ): void {
        $fixtureSlugs = array_map(
            static fn (string $action): string => sprintf(
                '%s-roadmap-project',
                $action,
            ),
            self::ACTIONS,
        );

        $projects = Project::query()
            ->forOrganization($organization->id)
            ->whereIn('slug', $fixtureSlugs)
            ->get();

        foreach ($projects as $project) {
            /*
             * Project deletion is intentionally restricted by the schema.
             * Retiring the route slug preserves all approval, execution,
             * roadmap, audit, and reconciliation history.
             */
            $project->forceFill([
                'slug' => sprintf(
                    '%s-retired-%s',
                    $project->slug,
                    strtolower((string) Str::ulid()),
                ),
                'archived_at' => now(),
            ])->save();
        }
    }

    /**
     * Create one fresh project and roadmap awaiting human approval.
     */
    private function createAwaitingRoadmap(
        Organization $organization,
        User $user,
        string $action,
    ): Roadmap {
        $project = Project::factory()
            ->for($organization)
            ->create([
                'name' => Str::headline($action).' Roadmap Project',
                'slug' => $action.'-roadmap-project',
                'status' => ProjectStatus::AwaitingRoadmapApproval,
            ]);

        $document = Document::factory()
            ->for($project)
            ->create([
                'title' => 'Approved requirements',
            ]);

        $documentVersion = DocumentVersion::factory()
            ->for($document)
            ->approved()
            ->create();

        $configuration = ProjectConfigurationVersion::query()->create([
            'project_id' => $project->id,
            'schema_version' => 2,
            'revision' => 1,
            'actor_type' => AuditActorType::System,
            'actor_id' => 'roadmap-e2e-seeder',
            'change_reason' => 'roadmap_e2e_fixture',
            'snapshot' => [
                'schema_version' => 2,
                'revision' => 1,
                'policy' => [
                    'default_reasoning' => 'medium',
                    'provider' => [
                        'allowed_provider_ids' => ['simulation'],
                        'fallback_order' => ['simulation'],
                    ],
                    'budget' => [
                        'limit_minor' => null,
                        'currency' => 'USD',
                    ],
                    'approval' => [
                        'roadmap_required' => true,
                    ],
                ],
            ],
        ]);

        $documents = [[
            'document_id' => $document->id,
            'document_version_id' => $documentVersion->id,
            'version' => $documentVersion->version,
            'checksum_sha256' => $documentVersion->checksum_sha256,
        ]];

        $snapshot = ProjectContextSnapshot::query()->create([
            'project_id' => $project->id,
            'project_configuration_version_id' => $configuration->id,
            'configuration_revision' => 1,
            'identity_schema_version' => 1,
            'approved_document_set_fingerprint' => hash(
                'sha256',
                sprintf(
                    '%s-roadmap-context-%d',
                    $action,
                    $project->id,
                ),
            ),
            'approved_document_versions' => $documents,
        ]);

        $definition = WorkflowDefinition::query()
            ->where('definition_key', 'project_delivery')
            ->orderByDesc('version')
            ->firstOrFail();

        $workflow = WorkflowInstance::query()->create([
            'project_id' => $project->id,
            'workflow_definition_id' => $definition->id,
            'current_state' => 'awaiting_roadmap_approval',
            'transition_sequence' => 2,
        ]);

        $execution = Execution::factory()
            ->for($project)
            ->completed()
            ->create([
                'workflow_instance_id' => $workflow->id,
                'project_context_snapshot_id' => $snapshot->id,
                'capability' => 'planning.roadmap',
                'requested_reasoning_level' => ReasoningLevel::Medium,
            ]);

        $request = new PlanningExecutionRequest(
            projectId: $project->id,
            contextSnapshotId: $snapshot->id,
            contextFingerprint: $snapshot
                ->approved_document_set_fingerprint,
            reasoningLevel: ReasoningLevel::Medium,
            documents: [
                new PlanningSourceReference(
                    documentId: $document->id,
                    documentVersionId: $documentVersion->id,
                    version: $documentVersion->version,
                    checksumSha256: $documentVersion->checksum_sha256,
                ),
            ],
            configurationVersionId: $configuration->id,
            configurationRevision: 1,
            configurationSchemaVersion: 2,
            configuration: $configuration->snapshot,
            policies: $configuration->snapshot['policy'],
        );

        $provider = new SimulationPlanningProvider;

        $selection = ProviderSelection::fromProvider(
            requestedCapability: $execution->capability,
            provider: $provider,
            selectionSource: 'roadmap_e2e_seeder',
        );

        $roadmap = app(PersistRoadmap::class)->handle(
            $execution,
            $request,
            $provider->execute($request),
            $selection,
        );

        $approvalResult = app(CommandBus::class)->dispatch(
            new RequestApproval(
                projectId: $project->id,
                type: ApprovalType::Roadmap,
                idempotencyKey: sprintf(
                    'roadmap-e2e-%s-project-%d',
                    $action,
                    $project->id,
                ),
                correlationId: (string) Str::ulid(),
                workflowInstanceId: $workflow->id,
                executionId: $execution->id,
                requestedByUserId: $user->id,
                payload: $this->approvalPayload(
                    $roadmap,
                    $configuration->id,
                ),
            ),
        );

        /** @var Approval $approval */
        $approval = Approval::query()->findOrFail(
            $approvalResult->data['approval_id'],
        );

        $roadmap->forceFill([
            'approval_id' => $approval->id,
            'status' => 'awaiting_approval',
        ])->save();

        return $roadmap;
    }

    /**
     * Create deterministic external drift for reconciliation browser coverage.
     */
    private function createNotionConflict(
        Organization $organization,
        Roadmap $roadmap,
    ): void {
        $task = $roadmap->tasks()->firstOrFail();

        $fingerprint = hash(
            'sha256',
            'roadmap-e2e-notion-conflict-'.$task->id,
        );

        $mapping = ExternalTicketMapping::query()->create([
            'roadmap_task_id' => $task->id,
            'provider' => 'notion',
            'external_key' => 'roadmap-e2e-notion-'.$task->id,
            'page_id' => 'roadmap-e2e-notion-page-'.$task->id,
            'page_url' => 'https://www.notion.so/roadmap-e2e-notion-page-'
                .$task->id,
            'last_synchronized_fingerprint' => hash(
                'sha256',
                'roadmap-e2e-notion-published-'.$task->id,
            ),
            'state' => 'synchronized',
            'reconciliation_state' => 'external_drift',
            'reconciliation_fingerprint' => $fingerprint,
        ]);

        NotionReconciliationConflict::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $roadmap->project_id,
            'external_ticket_mapping_id' => $mapping->id,
            'current_fingerprint' => $fingerprint,
            'published_fingerprint' => $mapping
                ->last_synchronized_fingerprint,
            'external_fingerprint' => $fingerprint,
        ]);
    }

    /**
     * Build the exact approval candidate identity stored with the request.
     *
     * @return array<string, mixed>
     */
    private function approvalPayload(
        Roadmap $roadmap,
        int $configurationVersionId,
    ): array {
        return [
            'approval_authority' => 'human',
            'roadmap_id' => $roadmap->id,
            'revision' => $roadmap->revision,
            'content_version' => $roadmap->content_version,
            'candidate_fingerprint' => $roadmap->candidate_fingerprint,
            'output_fingerprint' => $roadmap->output_fingerprint,
            'project_context_snapshot_id' => $roadmap
                ->project_context_snapshot_id,
            'planning_execution_id' => $roadmap->planning_execution_id,
            'configuration_version_id' => $configurationVersionId,
        ];
    }
}
