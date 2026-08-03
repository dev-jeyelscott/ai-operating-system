<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Identity\OrganizationRole;
use App\Domain\Projects\ProjectType;
use App\Models\OfficeProjection;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use JsonException;
use RuntimeException;

/**
 * Creates or refreshes one deterministic office fixture for Playwright.
 */
final class PrepareOfficeE2ECommand extends Command
{
    protected $signature = 'app:e2e:prepare-office
        {--sequence=42 : Projection sequence to persist}
        {--json : Print only the browser fixture JSON}';

    protected $description =
        'Prepare a deterministic tenant-scoped office fixture for browser tests.';

    /**
     * Create the fixture only in non-production environments.
     */
    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error(
                'The office E2E fixture command is restricted to local and testing environments.',
            );

            return self::FAILURE;
        }

        $sequence = filter_var(
            $this->option('sequence'),
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                    'max_range' => 1_000_000,
                ],
            ],
        );

        if (! is_int($sequence)) {
            $this->error('The projection sequence must be a positive integer.');

            return self::INVALID;
        }

        $fixture = DB::transaction(
            fn (): array => $this->prepareFixture($sequence),
            attempts: 3,
        );

        try {
            $json = json_encode(
                $fixture,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'The office E2E fixture could not be encoded.',
                previous: $exception,
            );
        }

        if ($this->option('json')) {
            $this->line($json);

            return self::SUCCESS;
        }

        $this->info('Office E2E fixture prepared.');
        $this->line($json);

        return self::SUCCESS;
    }

    /**
     * Upsert the user, tenant, project, and durable projection.
     *
     * @return array{
     *     email: string,
     *     password: string,
     *     officeUrl: string,
     *     operationsUrl: string,
     *     agentId: string,
     *     agentRole: string,
     *     agentAction: string,
     *     projectionSequence: int
     * }
     */
    private function prepareFixture(int $sequence): array
    {
        $email = 'office-e2e@example.com';
        $password = 'Office-E2E-Password-2026';
        $agentId = '01KOFFICEE2EAGENT000000000';
        $agentRole = 'Frontend Engineer';
        $validating = $sequence > 42;
        $agentAction = $validating
            ? 'Validating the assigned ticket'
            : 'Implementing the assigned ticket';

        $user = User::query()->firstOrNew([
            'email' => $email,
        ]);

        $userAttributes = [
            'name' => 'Office E2E User',
            'email' => $email,
            'email_verified_at' => now(),
        ];

        /*
 * Preserve the current password hash when the deterministic password has not
 * changed. Rehashing on every fixture refresh would invalidate authenticated
 * browser sessions protected by Laravel's auth.session middleware.
 */
        if (
            ! $user->exists
            || ! Hash::check($password, (string) $user->password)
        ) {
            $userAttributes['password'] = Hash::make($password);
        }

        $user->forceFill($userAttributes)->save();

        $organization = Organization::query()->firstOrNew([
            'slug' => 'office-e2e',
        ]);

        $organization->forceFill([
            'name' => 'Office E2E Organization',
            'slug' => 'office-e2e',
        ])->save();

        $membership = OrganizationMembership::query()->firstOrNew([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
        ]);

        $membership->forceFill([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => OrganizationRole::Owner,
        ])->save();

        $project = Project::query()
            ->forOrganization($organization->id)
            ->where('slug', 'office-e2e-project')
            ->first();

        if (! $project instanceof Project) {
            $project = new Project;
            $project->organization()->associate($organization);
        }

        $project->forceFill([
            'name' => 'Office E2E Project',
            'slug' => 'office-e2e-project',
            'description' => 'Deterministic office browser-test fixture.',
            'project_type' => ProjectType::WebApplication,
            'status' => 'active',
            'status_changed_at' => now(),
            'archived_at' => null,
        ])->save();

        $operationsUrl = route(
            'organizations.projects.operations.index',
            [
                'organization' => $organization,
                'project' => $project,
            ],
        );

        $state = $this->projectionState(
            project: $project,
            operationsUrl: $operationsUrl,
            agentId: $agentId,
            agentRole: $agentRole,
            agentAction: $agentAction,
            validating: $validating,
            sequence: $sequence,
        );

        OfficeProjection::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'project_id' => $project->id,
            ],
            [
                'schema_version' => 1,
                'last_event_sequence' => $sequence,
                'last_event_id' => sprintf(
                    '01KOFFICEE2EEVENT%08d',
                    $sequence,
                ),
                'fingerprint' => hash(
                    'sha256',
                    json_encode(
                        $state,
                        JSON_THROW_ON_ERROR,
                    ),
                ),
                'state' => $state,
                'projected_at' => now(),
                'rebuilt_at' => null,
            ],
        );

        return [
            'email' => $email,
            'password' => $password,
            'officeUrl' => route(
                'organizations.projects.operations.office.index',
                [
                    'organization' => $organization,
                    'project' => $project,
                ],
            ),
            'operationsUrl' => $operationsUrl,
            'agentId' => $agentId,
            'agentRole' => $agentRole,
            'agentAction' => $agentAction,
            'projectionSequence' => $sequence,
        ];
    }

    /**
     * Build the durable office state excluding metadata columns.
     *
     * @return array<string, mixed>
     */
    private function projectionState(
        Project $project,
        string $operationsUrl,
        string $agentId,
        string $agentRole,
        string $agentAction,
        bool $validating,
        int $sequence,
    ): array {
        return [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'status' => 'active',
                'archived' => false,
            ],
            'workflow' => [
                'id' => '01KOFFICEE2EWORKFLOW000000',
                'state' => 'running',
                'transitionSequence' => $sequence,
                'completedAt' => null,
            ],
            'roadmap' => null,
            'summary' => [
                'activeAgents' => 1,
                'ticketsTotal' => 1,
                'ticketsByStatus' => [
                    'in_progress' => 1,
                ],
                'blockers' => 0,
                'pendingApprovals' => 0,
                'retriesScheduled' => 0,
                'recentDecisions' => 0,
            ],
            'rooms' => [
                $this->room('lobby', 'Lobby', 'idle'),
                $this->room(
                    'planning_room',
                    'Planning Room',
                    'completed',
                ),
                $this->room(
                    'development_floor',
                    'Development Floor',
                    $validating ? 'validating' : 'working',
                    activeAgents: 1,
                    agentIds: [$agentId],
                ),
                $this->room(
                    'qa_laboratory',
                    'QA Laboratory',
                    'idle',
                ),
                $this->room(
                    'approval_room',
                    'Approval Room',
                    'idle',
                ),
                $this->room(
                    'operations_area',
                    'Operations Area',
                    'idle',
                ),
                $this->room(
                    'archive',
                    'Completed Work',
                    'idle',
                ),
            ],
            'agents' => [
                [
                    'id' => $agentId,
                    'role' => $agentRole,
                    'layer' => 'development',
                    'room' => 'development_floor',
                    'capability' => 'development_execution',
                    'workflowState' => 'running',
                    'officeState' => $validating
                        ? 'validating'
                        : 'implementing',
                    'currentAction' => $agentAction,
                    'active' => true,
                    'provider' => 'simulation',
                    'requestedReasoning' => 'medium',
                    'effectiveReasoning' => 'simulated',
                    'ticketId' => 'AIOS-E2E',
                    'attemptCount' => 1,
                    'retryLimit' => 2,
                    'nextAttemptAt' => null,
                    'startedAt' => now()
                        ->subMinutes(5)
                        ->toIso8601String(),
                    'finishedAt' => null,
                    'contextUrl' => $operationsUrl,
                ],
            ],
            'indicators' => [
                [
                    'key' => 'blockers',
                    'label' => 'Blockers',
                    'count' => 0,
                    'severity' => 'none',
                    'actionable' => false,
                    'contextUrl' => null,
                ],
            ],
            'simulation' => [
                'labelRequired' => true,
                'executionProvider' => 'simulation',
                'actualState' => 'unverified',
            ],
        ];
    }

    /**
     * Build one room projection fixture.
     *
     * @param  list<string>  $agentIds
     * @return array<string, mixed>
     */
    private function room(
        string $key,
        string $label,
        string $state,
        int $activeAgents = 0,
        array $agentIds = [],
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'state' => $state,
            'activeAgents' => $activeAgents,
            'agentIds' => $agentIds,
            'actionableCount' => 0,
            'completedItems' => 0,
        ];
    }
}
