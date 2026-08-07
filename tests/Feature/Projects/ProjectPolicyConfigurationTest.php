<?php

declare(strict_types=1);

use App\Domain\Projects\Configuration\AutonomyLevel;
use App\Domain\Projects\Configuration\CodexProviderPolicy;
use App\Domain\Projects\Configuration\ProjectPolicyConfiguration;
use App\Domain\Projects\Configuration\ReasoningLevel;
use App\Domain\Projects\ProjectSetupStep;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ProjectSetupProgress;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

test(
    'project policy is normalized stored and advances setup progress',
    function (): void {
        [
            'project' => $project,
            'policyUpdateUrl' => $policyUpdateUrl,
            'reviewPageUrl' => $reviewPageUrl,
        ] = readyProjectPolicyConfigurationFixture($this);

        $payload = validProjectPolicyPayload();

        $payload['allowed_provider_ids'] = ' Simulation, OPENAI ';
        $payload['fallback_order'] = ' OPENAI, Simulation ';
        $payload['budget_currency'] = ' usd ';

        $this->put($policyUpdateUrl, $payload)
            ->assertRedirect($reviewPageUrl)
            ->assertSessionHasNoErrors();

        $configuration = $project
            ->configuration()
            ->firstOrFail();

        $progress = $project
            ->setupProgress()
            ->firstOrFail();

        /**
         * PostgreSQL jsonb preserves JSON meaning but does not guarantee the
         * insertion order of object keys. Assert each object member separately.
         *
         * @var array<string, mixed> $providerPolicy
         */
        $providerPolicy = $configuration->provider_policy;

        /**
         * Build the expected Codex policy through the domain normalizer so the
         * assertion matches the canonical representation stored by the system.
         */
        $expectedCodexPolicy = CodexProviderPolicy::fromArray(
            CodexProviderPolicy::defaults(),
        )->toArray();

        /**
         * @var array{
         *     roadmap_required: bool,
         *     ticket_execution_required: bool,
         *     merge_required: bool
         * } $approvalPolicy
         */
        $approvalPolicy = $configuration->approval_policy;

        /**
         * @var array{
         *     channels: list<string>,
         *     events: list<string>
         * } $notificationPolicy
         */
        $notificationPolicy = $configuration->notification_policy;

        expect($configuration->revision)
            ->toBe(5)
            ->and($configuration->default_reasoning)
            ->toBe(ReasoningLevel::High)
            ->and($providerPolicy)
            ->toHaveCount(3)
            ->and($providerPolicy['allowed_provider_ids'])
            ->toBe([
                'openai',
                'simulation',
            ])
            ->and($providerPolicy['fallback_order'])
            ->toBe([
                'openai',
                'simulation',
            ])
            ->and($providerPolicy['codex'])
            ->toEqual($expectedCodexPolicy)
            ->and($configuration->budget_limit_minor)
            ->toBe(12500)
            ->and($configuration->budget_currency)
            ->toBe('USD')
            ->and($configuration->automatic_retry_limit)
            ->toBe(2)
            ->and($configuration->required_documents)
            ->toBe([
                'product_charter',
                'requirements',
                'architecture',
            ])
            ->and($configuration->autonomy_level)
            ->toBe(AutonomyLevel::ApprovalRequired)
            ->and($approvalPolicy)
            ->toHaveCount(3)
            ->and($approvalPolicy['roadmap_required'])
            ->toBeTrue()
            ->and($approvalPolicy['ticket_execution_required'])
            ->toBeTrue()
            ->and($approvalPolicy['merge_required'])
            ->toBeTrue()
            ->and($notificationPolicy)
            ->toHaveCount(2)
            ->and($notificationPolicy['channels'])
            ->toBe(['in_app'])
            ->and($notificationPolicy['events'])
            ->toBe([
                'approval.requested',
                'roadmap.ready',
            ])
            ->and($progress->current_step)
            ->toBe(ProjectSetupStep::Review)
            ->and($progress->hasCompleted(ProjectSetupStep::Policies))
            ->toBeTrue();
    },
);

test(
    'invalid project policy does not mutate configuration or setup progress',
    /**
     * @param  array<string, mixed>  $payload
     */
    function (string $field, array $payload): void {
        [
            'project' => $project,
            'policyUpdateUrl' => $policyUpdateUrl,
            'policiesPageUrl' => $policiesPageUrl,
        ] = readyProjectPolicyConfigurationFixture($this);

        $configurationBefore = $project
            ->configuration()
            ->firstOrFail()
            ->getAttributes();

        $progressBefore = $project
            ->setupProgress()
            ->firstOrFail()
            ->getAttributes();

        $this->from($policiesPageUrl)
            ->put($policyUpdateUrl, $payload)
            ->assertRedirect($policiesPageUrl)
            ->assertSessionHasErrors($field);

        $configurationAfter = $project
            ->configuration()
            ->firstOrFail();

        $progressAfter = $project
            ->setupProgress()
            ->firstOrFail();

        expect($configurationAfter->getAttributes())
            ->toBe($configurationBefore)
            ->and($progressAfter->getAttributes())
            ->toBe($progressBefore)
            ->and($configurationAfter->revision)
            ->toBe(4)
            ->and($progressAfter->current_step)
            ->toBe(ProjectSetupStep::Policies)
            ->and($progressAfter->hasCompleted(
                ProjectSetupStep::Policies,
            ))
            ->toBeFalse();
    },
)->with([
    'unknown fallback provider' => [
        'provider_policy.fallback_order',
        invalidProjectPolicyPayload(
            field: 'fallback_order',
            invalidValue: 'anthropic',
        ),
    ],
    'malformed provider identifier' => [
        'provider_policy.allowed_provider_ids.1',
        invalidProjectPolicyPayload(
            field: 'allowed_provider_ids',
            invalidValue: 'simulation, invalid provider',
        ),
    ],
    'invalid reasoning level' => [
        'default_reasoning',
        invalidProjectPolicyPayload(
            field: 'default_reasoning',
            invalidValue: 'extreme',
        ),
    ],
    'budget exceeds maximum' => [
        'budget_limit_minor',
        invalidProjectPolicyPayload(
            field: 'budget_limit_minor',
            invalidValue: ProjectPolicyConfiguration::MAX_BUDGET_LIMIT_MINOR + 1,
        ),
    ],
    'retry limit exceeds maximum' => [
        'automatic_retry_limit',
        invalidProjectPolicyPayload(
            field: 'automatic_retry_limit',
            invalidValue: ProjectPolicyConfiguration::MAX_AUTOMATIC_RETRY_LIMIT + 1,
        ),
    ],
    'invalid autonomy level' => [
        'autonomy_level',
        invalidProjectPolicyPayload(
            field: 'autonomy_level',
            invalidValue: 'unrestricted',
        ),
    ],
    'required documents are empty' => [
        'required_documents',
        invalidProjectPolicyPayload(
            field: 'required_documents',
            invalidValue: '',
        ),
    ],
    'malformed approval boolean' => [
        'approval_policy.roadmap_required',
        invalidProjectPolicyPayload(
            field: 'roadmap_required',
            invalidValue: 'invalid',
        ),
    ],
]);

test(
    'equivalent normalized policy does not create another revision',
    function (): void {
        [
            'project' => $project,
            'policyUpdateUrl' => $policyUpdateUrl,
            'reviewPageUrl' => $reviewPageUrl,
        ] = readyProjectPolicyConfigurationFixture($this);

        $payload = validProjectPolicyPayload();

        $this->put($policyUpdateUrl, $payload)
            ->assertRedirect($reviewPageUrl)
            ->assertSessionHasNoErrors();

        $equivalentPayload = array_replace($payload, [
            /*
             * Allowlist ordering is not meaningful and is canonicalized.
             */
            'allowed_provider_ids' => ' OPENAI, SIMULATION ',

            /*
             * Fallback ordering is meaningful and therefore remains unchanged.
             */
            'fallback_order' => ' openai, simulation ',
            'budget_limit_minor' => '12500',
            'budget_currency' => 'usd',
            'automatic_retry_limit' => '2',
            'roadmap_required' => '1',
            'ticket_execution_required' => '1',
            'merge_required' => '1',
            'notification_events' => ' roadmap.ready, approval.requested ',
        ]);

        $this->put($policyUpdateUrl, $equivalentPayload)
            ->assertRedirect($reviewPageUrl)
            ->assertSessionHasNoErrors();

        $configuration = $project
            ->configuration()
            ->firstOrFail();

        $progress = $project
            ->setupProgress()
            ->firstOrFail();

        expect($configuration->revision)
            ->toBe(5)
            ->and($progress->current_step)
            ->toBe(ProjectSetupStep::Review)
            ->and($progress->hasCompleted(ProjectSetupStep::Policies))
            ->toBeTrue();
    },
);

/**
 * Create an authorized project and advance the wizard to the policies step.
 *
 * @return array{
 *     user: User,
 *     organization: Organization,
 *     project: Project,
 *     policyUpdateUrl: string,
 *     policiesPageUrl: string,
 *     reviewPageUrl: string
 * }
 */
function readyProjectPolicyConfigurationFixture(
    TestCase $testCase,
): array {
    config()->set([
        'rate-limits.project_commands.update.per_minute' => 100,
        'rate-limits.project_commands.update.per_hour' => 1000,
    ]);

    Cache::store((string) config('cache.limiter'))->flush();

    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    OrganizationMembership::factory()
        ->for($organization)
        ->for($user)
        ->owner()
        ->create();

    $project = Project::factory()
        ->for($organization)
        ->create();

    ProjectConfiguration::factory()
        ->for($project)
        ->create();

    ProjectSetupProgress::query()->create([
        'project_id' => $project->id,
        'current_step' => ProjectSetupStep::Details,
        'completed_steps' => [],
    ]);

    $testCase->actingAs($user)
        ->put(route('organizations.projects.setup.update', [
            'organization' => $organization,
            'project' => $project,
            'step' => ProjectSetupStep::Details,
        ]), [
            'languages' => 'PHP, TypeScript',
            'frameworks' => 'Laravel 13, Inertia.js 3, React',
            'databases' => 'PostgreSQL, Redis',
            'infrastructure' => 'Docker Compose, GitHub Actions',
            'package_managers' => 'Composer, pnpm',
            'runtimes' => 'PHP 8.5, Node.js 22',
        ])
        ->assertRedirect(route(
            'organizations.projects.setup.show',
            [
                'organization' => $organization,
                'project' => $project,
                'step' => ProjectSetupStep::Repository,
            ],
        ))
        ->assertSessionHasNoErrors();

    $testCase->put(route('organizations.projects.setup.update', [
        'organization' => $organization,
        'project' => $project,
        'step' => ProjectSetupStep::Repository,
    ]), [
        'repository_provider' => 'github',
        'repository_url' => 'https://github.com/example/project',
        'default_branch' => 'main',
        'integration_branch' => 'develop',
    ])
        ->assertRedirect(route(
            'organizations.projects.setup.show',
            [
                'organization' => $organization,
                'project' => $project,
                'step' => ProjectSetupStep::Integrations,
            ],
        ))
        ->assertSessionHasNoErrors();

    /*
     * Policy tests do not exercise the external Notion connection.
     */
    completeProjectIntegrationSetupForTesting($project);

    /*
     * Commands must be submitted to the generic setup update endpoint.
     */
    $testCase
        ->actingAs($user)
        ->put(route('organizations.projects.setup.update', [
            'organization' => $organization,
            'project' => $project,
            'step' => ProjectSetupStep::Commands,
        ]), [
            'build_command' => 'pnpm build',
            'test_command' => 'composer test && pnpm test:unit',
            'lint_command' => 'composer lint:check && pnpm lint:check',
            'static_analysis_command' => 'composer types:check && pnpm types:check',
            'security_command' => 'composer audit',
        ])
        ->assertRedirect(route(
            'organizations.projects.setup.show',
            [
                'organization' => $organization,
                'project' => $project,
                'step' => ProjectSetupStep::Policies,
            ],
        ))
        ->assertSessionHasNoErrors();

    Cache::store((string) config('cache.limiter'))->flush();

    return [
        'user' => $user,
        'organization' => $organization,
        'project' => $project,
        'policyUpdateUrl' => route(
            'organizations.projects.setup.update',
            [
                'organization' => $organization,
                'project' => $project,
                'step' => ProjectSetupStep::Policies,
            ],
        ),
        'policiesPageUrl' => route(
            'organizations.projects.setup.show',
            [
                'organization' => $organization,
                'project' => $project,
                'step' => ProjectSetupStep::Policies,
            ],
        ),
        'reviewPageUrl' => route(
            'organizations.projects.setup.show',
            [
                'organization' => $organization,
                'project' => $project,
                'step' => ProjectSetupStep::Review,
            ],
        ),
    ];
}

/**
 * Return a complete valid policy form payload.
 *
 * @return array<string, mixed>
 */
function validProjectPolicyPayload(): array
{
    return [
        'required_documents' => implode(', ', [
            'product_charter',
            'requirements',
            'architecture',
        ]),
        'default_reasoning' => 'high',
        'allowed_provider_ids' => 'simulation, openai',
        'fallback_order' => 'openai, simulation',
        'budget_limit_minor' => 12500,
        'budget_currency' => 'USD',
        'automatic_retry_limit' => 2,
        'autonomy_level' => 'approval_required',
        'roadmap_required' => true,
        'ticket_execution_required' => true,
        'merge_required' => true,
        'notification_events' => 'roadmap.ready, approval.requested',
    ];
}

/**
 * Replace one field in a valid policy payload.
 *
 * @return array<string, mixed>
 */
function invalidProjectPolicyPayload(
    string $field,
    mixed $invalidValue,
): array {
    return array_replace(
        validProjectPolicyPayload(),
        [$field => $invalidValue],
    );
}
