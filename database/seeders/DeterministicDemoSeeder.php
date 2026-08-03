<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Documents\DocumentClassification;
use App\Domain\Documents\DocumentStatus;
use App\Domain\Identity\OrganizationRole;
use App\Domain\Projects\Configuration\AutonomyLevel;
use App\Domain\Projects\Configuration\ProjectConfigurationSchema;
use App\Domain\Projects\Configuration\ReasoningLevel;
use App\Domain\Projects\Configuration\RepositoryProvider;
use App\Domain\Projects\ProjectStatus;
use App\Domain\Projects\ProjectType;
use App\Domain\Simulation\DeterministicScenario;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;

/**
 * Seeds realistic, clearly simulated projects for local demos and tests.
 *
 * @phpstan-type DemoDocument array{
 *     title: string,
 *     filename: string,
 *     status: DocumentStatus,
 *     classification: DocumentClassification,
 *     content: string,
 *     summary: string,
 *     conflicts: list<string>,
 *     gaps: list<string>,
 *     flags: list<string>
 * }
 * @phpstan-type DemoProject array{
 *     slug: string,
 *     name: string,
 *     description: string,
 *     status: ProjectStatus,
 *     scenario: DeterministicScenario,
 *     seed: int,
 *     documents: list<DemoDocument>
 * }
 */
final class DeterministicDemoSeeder extends Seeder
{
    private const string USER_EMAIL = 'demo-owner@example.test';

    private const string USER_PASSWORD = 'Demo-Password-2026';

    private const string ORGANIZATION_SLUG = 'aios-demonstration';

    /**
     * Seed the deterministic demo tenant without touching production data.
     */
    public function run(): void
    {
        $this->assertSafeEnvironment();

        DB::transaction(function (): void {
            $user = $this->prepareUser();
            $organization = $this->prepareOrganization($user);

            foreach ($this->projectDefinitions() as $definition) {
                $project = $this->prepareProject(
                    organization: $organization,
                    definition: $definition,
                );

                $this->prepareConfiguration($project);

                foreach ($definition['documents'] as $document) {
                    $this->prepareDocument(
                        project: $project,
                        scenario: $definition['scenario'],
                        seed: $definition['seed'],
                        definition: $document,
                    );
                }
            }
        }, attempts: 3);
    }

    /**
     * Prevent demo credentials and fixtures from entering shared environments.
     */
    private function assertSafeEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException(
                'Deterministic demo data may only be seeded in local or testing environments.',
            );
        }
    }

    /**
     * Create or refresh the deterministic local demo user.
     */
    private function prepareUser(): User
    {
        $user = User::query()->firstOrNew([
            'email' => self::USER_EMAIL,
        ]);

        $user->forceFill([
            'name' => 'AIOS Demo Owner',
            'email' => self::USER_EMAIL,
            'email_verified_at' => now(),
            'password' => Hash::make(self::USER_PASSWORD),
        ])->save();

        return $user;
    }

    /**
     * Create or refresh the deterministic demo organization and ownership.
     */
    private function prepareOrganization(User $user): Organization
    {
        $organization = Organization::query()->updateOrCreate(
            [
                'slug' => self::ORGANIZATION_SLUG,
            ],
            [
                'name' => 'AI Operating System Demonstration',
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
     * Create or refresh one stable demo project without deleting history.
     *
     * @param  DemoProject  $definition
     */
    private function prepareProject(
        Organization $organization,
        array $definition,
    ): Project {
        $project = Project::query()
            ->forOrganization($organization->id)
            ->where('slug', $definition['slug'])
            ->first();

        if (! $project instanceof Project) {
            $project = new Project;
            $project->organization()->associate($organization);
        }

        /*
         * Status is guarded in normal application commands. This local-only
         * fixture intentionally projects a deterministic demonstration state.
         */
        $project->forceFill([
            'organization_id' => $organization->id,
            'name' => $definition['name'],
            'slug' => $definition['slug'],
            'description' => $definition['description'],
            'project_type' => ProjectType::WebApplication,
            'status' => $definition['status'],
            'status_changed_at' => now(),
            'archived_at' => null,
        ])->save();

        return $project;
    }

    /**
     * Create or refresh a complete, secret-free project configuration.
     */
    private function prepareConfiguration(Project $project): void
    {
        $configuration = ProjectConfiguration::query()
            ->where('project_id', $project->id)
            ->first();

        if (! $configuration instanceof ProjectConfiguration) {
            $configuration = new ProjectConfiguration;
            $configuration->project()->associate($project);
        }

        $configuration->forceFill([
            'project_id' => $project->id,
            'schema_version' => ProjectConfigurationSchema::CURRENT_VERSION,
            'revision' => ProjectConfigurationSchema::INITIAL_REVISION,
            'technology_stack' => [
                'languages' => ['PHP', 'TypeScript'],
                'frameworks' => ['Laravel', 'Inertia.js', 'React'],
                'databases' => ['PostgreSQL'],
                'infrastructure' => ['Docker Compose', 'Redis'],
                'package_managers' => ['Composer', 'pnpm'],
                'runtimes' => ['PHP 8.5', 'Node.js'],
            ],
            'repository_provider' => RepositoryProvider::GitHub,
            'repository_url' => 'https://github.com/dev-jeyelscott/ai-operating-system',
            'default_branch' => 'main',
            'integration_branch' => 'develop',
            'build_command' => 'pnpm run build',
            'test_command' => 'php artisan test',
            'lint_command' => 'composer lint:check',
            'static_analysis_command' => 'composer types:check',
            'security_command' => 'composer supply-chain:check',
            'required_documents' => [
                'product_charter',
                'requirements',
                'architecture',
            ],
            'default_reasoning' => ReasoningLevel::High,
            'provider_policy' => [
                'allowed_provider_ids' => ['simulation'],
                'fallback_order' => ['simulation'],
            ],
            'budget_limit_minor' => 5_000,
            'budget_currency' => 'USD',
            'automatic_retry_limit' => 3,
            'autonomy_level' => AutonomyLevel::ApprovalRequired,
            'approval_policy' => ProjectConfigurationSchema::approvalPolicyDefaults(),
            'notification_policy' => [
                'channels' => ['in_app'],
                'events' => [
                    'approval_requested',
                    'workflow_blocked',
                    'merge_assessment_completed',
                ],
            ],
        ])->save();
    }

    /**
     * Create one immutable analyzed document version and restore its local file.
     *
     * @param  DemoDocument  $definition
     */
    private function prepareDocument(
        Project $project,
        DeterministicScenario $scenario,
        int $seed,
        array $definition,
    ): void {
        $document = Document::query()->firstOrCreate([
            'project_id' => $project->id,
            'title' => $definition['title'],
        ]);

        $content = $definition['content'];
        $checksum = hash('sha256', $content);
        $storagePath = sprintf(
            'demo/%s/%s',
            $project->slug,
            $definition['filename'],
        );
        $flags = array_values(array_unique([
            ...$definition['flags'],
            'scenario:'.$scenario->value,
            'simulation_only',
            'actual_state_unverified',
        ]));

        $version = DocumentVersion::query()->firstOrCreate(
            [
                'document_id' => $document->id,
                'version' => 1,
            ],
            [
                'original_filename' => $definition['filename'],
                'media_type' => 'text/plain',
                'byte_size' => strlen($content),
                'storage_disk' => 'local',
                'storage_path' => $storagePath,
                'checksum_sha256' => $checksum,
                'status' => $definition['status'],
                'classification' => $definition['classification'],
                'parser_name' => 'deterministic-demo-parser',
                'parser_version' => '1.0.0',
                'parsing_started_at' => now()->subSeconds(2),
                'parsed_at' => now()->subSecond(),
                'parsed_content' => $content,
                'analyzer_name' => 'deterministic-document-analyzer',
                'analyzer_version' => '1.0.0',
                'analysis_seed' => $seed,
                'analysis_started_at' => now()->subSecond(),
                'analysis_completed_at' => now(),
                'analysis_summary' => $definition['summary'],
                'analysis_conflicts' => $definition['conflicts'],
                'analysis_gaps' => $definition['gaps'],
                'analysis_flags' => $flags,
                'failure_code' => null,
                'failure_message' => null,
                'supersedes_document_version_id' => null,
            ],
        );

        $this->assertImmutableFixtureMatches(
            version: $version,
            checksum: $checksum,
            status: $definition['status'],
            classification: $definition['classification'],
        );

        if (! Storage::disk('local')->put($storagePath, $content)) {
            throw new RuntimeException(sprintf(
                'Unable to store deterministic demo document [%s].',
                $storagePath,
            ));
        }
    }

    /**
     * Fail when an existing immutable version no longer matches its fixture.
     */
    private function assertImmutableFixtureMatches(
        DocumentVersion $version,
        string $checksum,
        DocumentStatus $status,
        DocumentClassification $classification,
    ): void {
        if (
            ! hash_equals($version->checksum_sha256, $checksum)
            || $version->status !== $status
            || $version->classification !== $classification
        ) {
            throw new LogicException(sprintf(
                'Demo document version [%d] drifted. Add a new version instead of mutating immutable evidence.',
                $version->id,
            ));
        }
    }

    /**
     * Return the four realistic demo projects required by AIOS-152.
     *
     * @return list<DemoProject>
     */
    private function projectDefinitions(): array
    {
        return [
            [
                'slug' => 'demo-happy-path',
                'name' => 'Demo — Happy Path',
                'description' => 'A complete, approved source set for the deterministic happy-path simulation. Demo content is simulated and unverified.',
                'status' => ProjectStatus::ReadyForPlanning,
                'scenario' => DeterministicScenario::HappyPath,
                'seed' => 151_001,
                'documents' => [
                    $this->document(
                        title: 'Product Charter',
                        filename: 'product-charter.txt',
                        classification: DocumentClassification::Specification,
                        content: "AI Operating System demonstration charter.\n\nGoal: prove the full simulation-first delivery workflow while retaining human approval at consequential gates.",
                        summary: 'Defines the approved demo objective and simulation boundary.',
                    ),
                    $this->document(
                        title: 'Requirements',
                        filename: 'requirements.txt',
                        classification: DocumentClassification::Specification,
                        content: 'The project must generate a traceable roadmap, publish tasks idempotently, simulate development, run independent QA, and present an authorized simulated merge decision.',
                        summary: 'Defines the happy-path functional acceptance criteria.',
                    ),
                    $this->document(
                        title: 'Architecture Baseline',
                        filename: 'architecture-baseline.txt',
                        classification: DocumentClassification::Architecture,
                        content: 'Use a Laravel modular monolith with PostgreSQL, Redis, Inertia, React, deterministic workflow transitions, and develop-only pull request policy.',
                        summary: 'Defines the approved technical architecture for the demo.',
                    ),
                ],
            ],
            [
                'slug' => 'demo-conflicting-documents',
                'name' => 'Demo — Conflicting Documents',
                'description' => 'Approved documents intentionally disagree so Layer 1 must block and request a human decision. Demo content is simulated and unverified.',
                'status' => ProjectStatus::ReadyForPlanning,
                'scenario' => DeterministicScenario::ConflictingDocuments,
                'seed' => 151_002,
                'documents' => [
                    $this->document(
                        title: 'Conflict Product Charter',
                        filename: 'product-charter.txt',
                        classification: DocumentClassification::Specification,
                        content: 'The demonstration must surface approved-source conflicts instead of silently choosing one rule.',
                        summary: 'Defines the expected conflict-handling behavior.',
                    ),
                    $this->document(
                        title: 'Conflict Requirements',
                        filename: 'requirements.txt',
                        classification: DocumentClassification::Specification,
                        content: 'All authoritative application data must be stored in PostgreSQL.',
                        summary: 'Requires PostgreSQL as the authoritative database.',
                    ),
                    $this->document(
                        title: 'Conflicting Architecture',
                        filename: 'architecture.txt',
                        classification: DocumentClassification::Architecture,
                        content: 'The authoritative application database must be MySQL and PostgreSQL must not be used.',
                        summary: 'Intentionally conflicts with the approved requirements database rule.',
                        conflicts: [
                            'Requirements mandate PostgreSQL while architecture mandates MySQL.',
                        ],
                        flags: ['human_decision_required'],
                    ),
                ],
            ],
            [
                'slug' => 'demo-notion-transient-failure',
                'name' => 'Demo — Notion Retry',
                'description' => 'A blocked publication-recovery example showing bounded retry and duplicate prevention. Demo content is simulated and unverified.',
                'status' => ProjectStatus::Blocked,
                'scenario' => DeterministicScenario::NotionTransientFailure,
                'seed' => 151_003,
                'documents' => [
                    $this->document(
                        title: 'Publication Requirements',
                        filename: 'publication-requirements.txt',
                        classification: DocumentClassification::Specification,
                        content: 'Approved roadmap tasks must be upserted to Notion by stable external key. Retrying a partial failure must not duplicate successful pages.',
                        summary: 'Defines idempotent Notion publication requirements.',
                    ),
                    $this->document(
                        title: 'Notion Recovery Runbook',
                        filename: 'notion-recovery-runbook.txt',
                        classification: DocumentClassification::Operational,
                        content: 'Simulated condition: the first publication attempt receives a transient provider failure after some tasks succeed. Retry only failed mappings and reconcile external identifiers.',
                        summary: 'Documents the simulated transient failure and recovery procedure.',
                        flags: ['notion_transient_failure', 'retry_required'],
                    ),
                ],
            ],
            [
                'slug' => 'demo-high-risk-merge',
                'name' => 'Demo — High-Risk Merge',
                'description' => 'An active high-risk assessment example requiring explicit human review. Demo content is simulated and unverified.',
                'status' => ProjectStatus::Active,
                'scenario' => DeterministicScenario::MergeReadyHighRisk,
                'seed' => 151_004,
                'documents' => [
                    $this->document(
                        title: 'High-Risk Product Charter',
                        filename: 'product-charter.txt',
                        classification: DocumentClassification::Specification,
                        content: 'The demonstration must distinguish technically merge-ready work from work that still requires explicit human risk acceptance.',
                        summary: 'Defines the high-risk merge advisory objective.',
                    ),
                    $this->document(
                        title: 'Security Baseline',
                        filename: 'security-baseline.txt',
                        classification: DocumentClassification::Policy,
                        content: 'Authorization, tenant isolation, secret redaction, evidence verification, and human approval are mandatory before consequential changes advance.',
                        summary: 'Defines mandatory security and approval controls.',
                        flags: ['high_risk', 'human_review_required'],
                    ),
                    $this->document(
                        title: 'Database Migration Plan',
                        filename: 'database-migration-plan.txt',
                        classification: DocumentClassification::Architecture,
                        content: 'The simulated change affects a critical PostgreSQL workflow and has complex rollback requirements. No destructive production operation is authorized.',
                        summary: 'Describes high database impact and rollback complexity.',
                        gaps: [
                            'Verified rollout, rollback, and production monitoring evidence is still required.',
                        ],
                        flags: ['high_risk', 'rollback_complexity_high'],
                    ),
                ],
            ],
        ];
    }

    /**
     * Build one approved deterministic document definition.
     *
     * @param  list<string>  $conflicts
     * @param  list<string>  $gaps
     * @param  list<string>  $flags
     * @return DemoDocument
     */
    private function document(
        string $title,
        string $filename,
        DocumentClassification $classification,
        string $content,
        string $summary,
        array $conflicts = [],
        array $gaps = [],
        array $flags = [],
    ): array {
        return [
            'title' => $title,
            'filename' => $filename,
            'status' => DocumentStatus::Approved,
            'classification' => $classification,
            'content' => $content,
            'summary' => $summary,
            'conflicts' => $conflicts,
            'gaps' => $gaps,
            'flags' => $flags,
        ];
    }
}
