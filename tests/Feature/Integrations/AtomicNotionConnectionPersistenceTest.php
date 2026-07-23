<?php

declare(strict_types=1);

use App\Application\Integrations\Contracts\IntegrationCredentialCipher;
use App\Application\Integrations\SaveProjectIntegrationCredential;
use App\Domain\Audit\AuditEventType;
use App\Domain\Integrations\IntegrationProvider;
use App\Domain\Projects\ProjectSetupStep;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ProjectConfigurationVersion;
use App\Models\ProjectIntegration;
use App\Models\ProjectSetupProgress;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    config()->set([
        'services.notion.base_url' => 'https://api.notion.com/v1',

        'services.notion.version' => '2026-03-11',

        'services.notion.retry_attempts' => 1,

        'rate-limits.project_commands.integration_test.per_minute' => 100,

        'rate-limits.project_commands.integration_test.per_hour' => 1000,
    ]);

    Cache::store(
        (string) config('cache.limiter'),
    )->flush();

    Http::preventStrayRequests();
});

test(
    'the provider request executes outside a database transaction and all local state commits together',
    function (): void {
        $fixture = atomicNotionConnectionFixture();

        $transactionBaseline = DB::transactionLevel();

        $credential =
            'secret_notion_atomic_abcdefghijklmnopqrstuvwxyz';

        $provider = fakeAtomicSuccessfulNotionConnection(
            expectedTransactionLevel: $transactionBaseline,
        );

        $this->actingAs($fixture['user'])
            ->post($fixture['url'], [
                'credential' => $credential,
                'database_id' => $provider['database_id'],
            ])
            ->assertSessionHasNoErrors();

        expect(DB::transactionLevel())
            ->toBe($transactionBaseline);

        $configuration = ProjectConfiguration::query()
            ->where('project_id', $fixture['project']->id)
            ->firstOrFail();

        $progress = ProjectSetupProgress::query()
            ->where('project_id', $fixture['project']->id)
            ->firstOrFail();

        $storedCredential = ProviderCredential::query()
            ->forProject($fixture['project']->id)
            ->where(
                'provider',
                IntegrationProvider::Notion->value,
            )
            ->firstOrFail();

        $integration = ProjectIntegration::query()
            ->forProject($fixture['project']->id)
            ->where(
                'provider',
                IntegrationProvider::Notion->value,
            )
            ->firstOrFail();

        expect($configuration->revision)
            ->toBe(2)
            ->and($progress->current_step)
            ->toBe(ProjectSetupStep::Commands)
            ->and(
                $progress->hasCompleted(
                    ProjectSetupStep::Integrations,
                ),
            )
            ->toBeTrue()
            ->and($storedCredential->version)
            ->toBe(1)
            ->and($integration->database_id)
            ->toBe($provider['database_id'])
            ->and(
                ProjectConfigurationVersion::query()
                    ->where(
                        'project_id',
                        $fixture['project']->id,
                    )
                    ->count(),
            )
            ->toBe(1)
            ->and(
                AuditEvent::query()
                    ->where(
                        'project_id',
                        $fixture['project']->id,
                    )
                    ->where(
                        'event_type',
                        AuditEventType::NotionConnectionTestSucceeded
                            ->value,
                    )
                    ->count(),
            )
            ->toBe(1);

        $decrypted = app(
            IntegrationCredentialCipher::class,
        )->decrypt(
            $storedCredential->secret_ciphertext,
        );

        expect($decrypted->reveal())
            ->toBe($credential);
    },
);

test(
    'calling the route before the Integrations step performs no provider request or local mutation',
    function (): void {
        $fixture = atomicNotionConnectionFixture(
            currentStep: ProjectSetupStep::Repository,
            completedSteps: [
                ProjectSetupStep::Details->value,
            ],
        );

        Http::fake();

        $configurationBefore =
            $fixture['project']->configuration()
                ->firstOrFail()
                ->getAttributes();

        $progressBefore =
            $fixture['project']->setupProgress()
                ->firstOrFail()
                ->getAttributes();

        $this->actingAs($fixture['user'])
            ->post($fixture['url'], [
                'credential' => 'secret_notion_future_abcdefghijklmnopqrstuvwxyz',

                'database_id' => atomicNotionDatabaseId(),
            ])
            ->assertSessionHasErrors('step');

        Http::assertNothingSent();

        expect(
            $fixture['project']->configuration()
                ->firstOrFail()
                ->getAttributes(),
        )
            ->toBe($configurationBefore)
            ->and(
                $fixture['project']->setupProgress()
                    ->firstOrFail()
                    ->getAttributes(),
            )
            ->toBe($progressBefore)
            ->and(ProviderCredential::query()->count())
            ->toBe(0)
            ->and(ProjectIntegration::query()->count())
            ->toBe(0)
            ->and(ProjectConfigurationVersion::query()->count())
            ->toBe(0)
            ->and(AuditEvent::query()->count())
            ->toBe(0);
    },
);

dataset('atomic Notion persistence failures', [
    'audit persistence failure' => [
        static function (): void {
            AuditEvent::creating(
                static function (): never {
                    throw new RuntimeException(
                        'Injected audit persistence failure.',
                    );
                },
            );
        },

        'Injected audit persistence failure.',
    ],

    'configuration history failure' => [
        static function (): void {
            ProjectConfigurationVersion::creating(
                static function (): never {
                    throw new RuntimeException(
                        'Injected configuration history failure.',
                    );
                },
            );
        },

        'Injected configuration history failure.',
    ],

    'setup progress failure' => [
        static function (): void {
            ProjectSetupProgress::updating(
                static function (): never {
                    throw new RuntimeException(
                        'Injected setup progress failure.',
                    );
                },
            );
        },

        'Injected setup progress failure.',
    ],
]);

test(
    'a persistence boundary failure rolls back every successful local side effect',
    function (
        Closure $injectFailure,
        string $expectedMessage,
    ): void {
        $fixture = atomicNotionConnectionFixture();

        $transactionBaseline =
            DB::transactionLevel();

        $provider =
            fakeAtomicSuccessfulNotionConnection(
                expectedTransactionLevel: $transactionBaseline,
            );

        /*
         * Register the failure after fixture creation so setup records can be
         * prepared normally.
         */
        $injectFailure();

        $this->withoutExceptionHandling();

        expect(
            fn () => $this->actingAs($fixture['user'])
                ->post($fixture['url'], [
                    'credential' => 'secret_notion_rollback_abcdefghijklmnopqrstuvwxyz',

                    'database_id' => $provider['database_id'],
                ]),
        )->toThrow(
            RuntimeException::class,
            $expectedMessage,
        );

        /*
         * Laravel must unwind only the command transaction and return to the
         * RefreshDatabase test transaction.
         */
        expect(DB::transactionLevel())
            ->toBe($transactionBaseline)
            ->and(ProviderCredential::query()->count())
            ->toBe(0)
            ->and(ProjectIntegration::query()->count())
            ->toBe(0)
            ->and(ProjectConfigurationVersion::query()->count())
            ->toBe(0)
            ->and(AuditEvent::query()->count())
            ->toBe(0);

        $configuration = ProjectConfiguration::query()
            ->where('project_id', $fixture['project']->id)
            ->firstOrFail();

        $progress = ProjectSetupProgress::query()
            ->where('project_id', $fixture['project']->id)
            ->firstOrFail();

        expect($configuration->revision)
            ->toBe(1)
            ->and($progress->current_step)
            ->toBe(ProjectSetupStep::Integrations)
            ->and(
                $progress->hasCompleted(
                    ProjectSetupStep::Integrations,
                ),
            )
            ->toBeFalse();
    },
)->with('atomic Notion persistence failures');

test(
    'a concurrent credential rotation rejects the stale provider result',
    function (): void {
        $fixture = atomicNotionConnectionFixture();

        $initialCredential =
            'secret_notion_initial_abcdefghijklmnopqrstuvwxyz';

        $rotatedCredential =
            'secret_notion_rotated_abcdefghijklmnopqrstuvwxyz';

        app(SaveProjectIntegrationCredential::class)
            ->handle(
                actorUserId: $fixture['user']->id,
                organizationId: $fixture['organization']->id,
                projectId: $fixture['project']->id,
                provider: IntegrationProvider::Notion,
                plaintextCredential: $initialCredential,
            );

        /*
        * Capture the RefreshDatabase transaction level before registering the
        * provider callback.
        */
        $transactionBaseline = DB::transactionLevel();

        fakeAtomicSuccessfulNotionConnection(
            beforeDatabaseResponse: function () use (
                $fixture,
                $rotatedCredential,
                $transactionBaseline,
            ): void {
                /*
                * Provider I/O must not add another application transaction.
                */
                expect(DB::transactionLevel())
                    ->toBe($transactionBaseline);

                /*
                * Simulate another command rotating the credential while the
                * original provider request is still in progress.
                */
                app(
                    SaveProjectIntegrationCredential::class,
                )->handle(
                    actorUserId: $fixture['user']->id,
                    organizationId: $fixture['organization']->id,
                    projectId: $fixture['project']->id,
                    provider: IntegrationProvider::Notion,
                    plaintextCredential: $rotatedCredential,
                );
            },
            expectedTransactionLevel: $transactionBaseline,
        );

        $this->withoutExceptionHandling();

        expect(
            fn () => $this->actingAs($fixture['user'])
                ->post($fixture['url'], [
                    'database_id' => atomicNotionDatabaseId(),
                ]),
        )->toThrow(
            ValidationException::class,
        );

        $storedCredential = ProviderCredential::query()
            ->forProject($fixture['project']->id)
            ->where(
                'provider',
                IntegrationProvider::Notion->value,
            )
            ->firstOrFail();

        $decrypted = app(
            IntegrationCredentialCipher::class,
        )->decrypt(
            $storedCredential->secret_ciphertext,
        );

        expect($storedCredential->version)
            ->toBe(2)
            ->and($decrypted->reveal())
            ->toBe($rotatedCredential)
            ->and(ProjectIntegration::query()->count())
            ->toBe(0)
            ->and(ProjectConfigurationVersion::query()->count())
            ->toBe(0)
            ->and(
                ProjectConfiguration::query()
                    ->where(
                        'project_id',
                        $fixture['project']->id,
                    )
                    ->firstOrFail()
                    ->revision,
            )
            ->toBe(1);
    },
);

test(
    'replaying the same successful request creates no duplicate history or audit records',
    function (): void {
        $fixture = atomicNotionConnectionFixture();

        $credential =
            'secret_notion_replay_abcdefghijklmnopqrstuvwxyz';

        $transactionBaseline = DB::transactionLevel();

        $provider = fakeAtomicSuccessfulNotionConnection(
            expectedTransactionLevel: $transactionBaseline,
        );

        $payload = [
            'credential' => $credential,
            'database_id' => $provider['database_id'],
        ];

        $this->actingAs($fixture['user'])
            ->post($fixture['url'], $payload)
            ->assertSessionHasNoErrors();

        $versionCount =
            ProjectConfigurationVersion::query()
                ->where(
                    'project_id',
                    $fixture['project']->id,
                )
                ->count();

        $auditCount = AuditEvent::query()
            ->where(
                'project_id',
                $fixture['project']->id,
            )
            ->count();

        /*
         * Re-register deterministic provider responses for the replay.
         */
        fakeAtomicSuccessfulNotionConnection(
            expectedTransactionLevel: $transactionBaseline,
        );

        $this->actingAs($fixture['user'])
            ->post($fixture['url'], $payload)
            ->assertSessionHasNoErrors();

        expect(
            ProjectConfigurationVersion::query()
                ->where(
                    'project_id',
                    $fixture['project']->id,
                )
                ->count(),
        )
            ->toBe($versionCount)
            ->and(
                AuditEvent::query()
                    ->where(
                        'project_id',
                        $fixture['project']->id,
                    )
                    ->count(),
            )
            ->toBe($auditCount)
            ->and(
                ProviderCredential::query()
                    ->forProject($fixture['project']->id)
                    ->where(
                        'provider',
                        IntegrationProvider::Notion->value,
                    )
                    ->firstOrFail()
                    ->version,
            )
            ->toBe(1)
            ->and(
                ProjectConfiguration::query()
                    ->where(
                        'project_id',
                        $fixture['project']->id,
                    )
                    ->firstOrFail()
                    ->revision,
            )
            ->toBe(2);
    },
);

/**
 * Create an owner and a project at a configurable setup position.
 *
 * @param  list<string>  $completedSteps
 * @return array{
 *     user: User,
 *     organization: Organization,
 *     project: Project,
 *     url: string
 * }
 */
function atomicNotionConnectionFixture(
    ProjectSetupStep $currentStep =
        ProjectSetupStep::Integrations,
    array $completedSteps = [
        'details',
        'repository',
    ],
): array {
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
        'current_step' => $currentStep,
        'completed_steps' => $completedSteps,
    ]);

    return [
        'user' => $user,
        'organization' => $organization,
        'project' => $project,
        'url' => route(
            'organizations.projects.integrations.notion.test',
            [
                'organization' => $organization,
                'project' => $project,
            ],
        ),
    ];
}

/**
 * Register successful deterministic Notion responses.
 *
 * The expected transaction level is captured after Laravel has booted and
 * before the application command begins. RefreshDatabase already owns the
 * outer test transaction, so provider calls must preserve that baseline.
 *
 * The optional callback executes during the database response and allows the
 * test to simulate concurrent local persistence while provider I/O is active.
 *
 * @return array{
 *     workspace_id: string,
 *     database_id: string,
 *     data_source_id: string
 * }
 */
function fakeAtomicSuccessfulNotionConnection(
    ?Closure $beforeDatabaseResponse = null,
    ?int $expectedTransactionLevel = null,
): array {
    $workspaceId =
        '17ab3186-873d-418f-b899-c3f6a43f68de';

    $databaseId = atomicNotionDatabaseId();

    $dataSourceId =
        '248104cd-477e-80af-bc30-000bd28de8f9';

    /*
     * This function is only called from inside running tests, after Laravel
     * has initialized the database facade.
     */
    $expectedTransactionLevel ??=
        DB::transactionLevel();

    Http::fake([
        'https://api.notion.com/v1/users/me' => static function () use (
            $workspaceId,
            $expectedTransactionLevel,
        ) {
            /*
             * External provider I/O must not add an application-owned
             * database transaction around the HTTP request.
             */
            expect(DB::transactionLevel())
                ->toBe($expectedTransactionLevel);

            return Http::response([
                'object' => 'user',
                'id' => '9188c6a5-7381-452f-b3dc-d4865aa89bdf',
                'type' => 'bot',
                'name' => 'AIOS',
                'bot' => [
                    'owner' => [
                        'type' => 'workspace',
                        'workspace' => true,
                    ],
                    'workspace_name' => 'AI Operating System',
                    'workspace_id' => $workspaceId,
                ],
            ], 200, [
                'x-request-id' => 'req-self',
            ]);
        },

        "https://api.notion.com/v1/databases/{$databaseId}" => static function () use (
            $beforeDatabaseResponse,
            $databaseId,
            $dataSourceId,
            $expectedTransactionLevel,
        ) {
            /*
             * The database lookup must also execute at the original test
             * transaction level.
             */
            expect(DB::transactionLevel())
                ->toBe($expectedTransactionLevel);

            $beforeDatabaseResponse?->__invoke();

            return Http::response([
                'object' => 'database',
                'id' => $databaseId,
                'title' => [
                    [
                        'type' => 'text',
                        'plain_text' => 'AIOS Tickets',
                    ],
                ],
                'data_sources' => [
                    [
                        'id' => $dataSourceId,
                        'name' => 'Tickets',
                    ],
                ],
            ], 200, [
                'x-request-id' => 'req-database',
            ]);
        },
    ]);

    return [
        'workspace_id' => $workspaceId,
        'database_id' => $databaseId,
        'data_source_id' => $dataSourceId,
    ];
}

/**
 * Return the stable Notion database fixture ID.
 */
function atomicNotionDatabaseId(): string
{
    return 'd9824bdc-8445-4327-be8b-5b47500af6ce';
}
