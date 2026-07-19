<?php

declare(strict_types=1);

use App\Application\Integrations\Contracts\IntegrationCredentialCipher;
use App\Application\Integrations\SaveProjectIntegrationCredential;
use App\Domain\Audit\AuditEventType;
use App\Domain\Identity\OrganizationRole;
use App\Domain\Integrations\IntegrationProvider;
use App\Domain\Integrations\NotionConnectionFailureCode;
use App\Domain\Integrations\NotionConnectionStatus;
use App\Domain\Projects\ProjectSetupStep;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ProjectIntegration;
use App\Models\ProjectSetupProgress;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set([
        'services.notion.base_url' => 'https://api.notion.com/v1',

        'services.notion.version' => '2026-03-11',

        /*
         * Most feature tests verify mapping rather than retry timing.
         */
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
    'an owner validates and persists a Notion connection',
    function (): void {
        [
            'user' => $user,
            'organization' => $organization,
            'project' => $project,
            'url' => $url,
        ] = notionConnectionFixture(
            OrganizationRole::Owner,
        );

        $credential =
            'secret_notion_abcdefghijklmnopqrstuvwxyz';

        $workspaceId =
            '17ab3186-873d-418f-b899-c3f6a43f68de';

        $databaseId =
            'd9824bdc-8445-4327-be8b-5b47500af6ce';

        Http::fake([
            'https://api.notion.com/v1/users/me' => Http::response([
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
            ]),

            "https://api.notion.com/v1/databases/{$databaseId}" => Http::response([
                'object' => 'database',
                'id' => $databaseId,
                'title' => [
                    [
                        'type' => 'text',
                        'plain_text' => 'AIOS Tickets',
                    ],
                ],
                'data_sources' => [],
            ], 200, [
                'x-request-id' => 'req-database',
            ]),
        ]);

        $this->actingAs($user)
            ->post($url, [
                'credential' => $credential,
                'database_id' => 'https://www.notion.so/AIOS-Tickets-'.
                    str_replace('-', '', $databaseId),
            ])
            ->assertRedirect(route(
                'organizations.projects.setup.show',
                [
                    'organization' => $organization,
                    'project' => $project,
                    'step' => ProjectSetupStep::Commands,
                ],
            ))
            ->assertSessionHasNoErrors()
            ->assertDontSee($credential);

        $this->assertDatabaseHas(
            'project_integrations',
            [
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'provider' => IntegrationProvider::Notion->value,
                'workspace_id' => $workspaceId,
                'database_id' => $databaseId,
                'connection_status' => NotionConnectionStatus::Connected->value,
                'last_failure_code' => null,
            ],
        );

        $rawCredential = DB::table(
            'provider_credentials',
        )
            ->where('project_id', $project->id)
            ->where(
                'provider',
                IntegrationProvider::Notion->value,
            )
            ->firstOrFail();

        expect($rawCredential->secret_ciphertext)
            ->not->toBe($credential)
            ->and($rawCredential->secret_ciphertext)
            ->not->toContain($credential);

        $progress = $project->setupProgress()
            ->firstOrFail();

        expect($progress->current_step)
            ->toBe(ProjectSetupStep::Commands)
            ->and(
                $progress->hasCompleted(
                    ProjectSetupStep::Integrations,
                ),
            )
            ->toBeTrue()
            ->and(
                $project->configuration()
                    ->firstOrFail()
                    ->revision,
            )
            ->toBe(2);

        Http::assertSentCount(2);

        Http::assertSent(
            static fn (Request $request): bool => $request->method() === 'GET'
                && $request->url()
                    === 'https://api.notion.com/v1/users/me'
                && $request->hasHeader(
                    'Authorization',
                    'Bearer '.$credential,
                )
                && $request->hasHeader(
                    'Notion-Version',
                    '2026-03-11',
                ),
        );

        expect(
            AuditEvent::query()
                ->where(
                    'event_type',
                    AuditEventType::NotionConnectionTestSucceeded
                        ->value,
                )
                ->where(
                    'project_id',
                    $project->id,
                )
                ->count(),
        )->toBe(1);
    },
);

test(
    'a database not shared with the integration does not advance setup',
    function (): void {
        [
            'user' => $user,
            'organization' => $organization,
            'project' => $project,
            'url' => $url,
        ] = notionConnectionFixture(
            OrganizationRole::Owner,
        );

        $databaseId =
            'd9824bdc-8445-4327-be8b-5b47500af6ce';

        app(SaveProjectIntegrationCredential::class)
            ->handle(
                actorUserId: $user->id,
                organizationId: $organization->id,
                projectId: $project->id,
                provider: IntegrationProvider::Notion,
                plaintextCredential: 'secret_notion_existing_abcdefghijklmnopqrstuvwxyz',
            );

        Http::fake([
            'https://api.notion.com/v1/users/me' => Http::response([
                'object' => 'user',
                'type' => 'bot',
                'bot' => [
                    'workspace_name' => 'AIOS',
                    'workspace_id' => '17ab3186-873d-418f-b899-c3f6a43f68de',
                ],
            ]),

            "https://api.notion.com/v1/databases/{$databaseId}" => Http::response([
                'object' => 'error',
                'status' => 404,
                'code' => 'object_not_found',
                'message' => 'Could not find database.',
                'request_id' => 'req-not-found',
            ], 404),
        ]);

        $this->actingAs($user)
            ->from(route(
                'organizations.projects.setup.show',
                [
                    'organization' => $organization,
                    'project' => $project,
                    'step' => ProjectSetupStep::Integrations,
                ],
            ))
            ->post($url, [
                'database_id' => $databaseId,
            ])
            ->assertSessionHasErrors('connection');

        $integration = ProjectIntegration::query()
            ->forProject($project->id)
            ->firstOrFail();

        expect($integration->connection_status)
            ->toBe(NotionConnectionStatus::Failed)
            ->and($integration->last_failure_code)
            ->toBe(
                NotionConnectionFailureCode::DatabaseNotShared,
            )
            ->and(
                $project->setupProgress()
                    ->firstOrFail()
                    ->current_step,
            )
            ->toBe(ProjectSetupStep::Integrations)
            ->and(
                $project->configuration()
                    ->firstOrFail()
                    ->revision,
            )
            ->toBe(1);
    },
);

test(
    'a failed candidate credential is not stored',
    function (): void {
        [
            'user' => $user,
            'organization' => $organization,
            'project' => $project,
            'url' => $url,
        ] = notionConnectionFixture(
            OrganizationRole::Owner,
        );

        $existingCredential =
            'secret_notion_existing_abcdefghijklmnopqrstuvwxyz';

        $invalidCandidate =
            'secret_notion_invalid_zyxwvutsrqponmlkjihgfedcba';

        app(SaveProjectIntegrationCredential::class)
            ->handle(
                actorUserId: $user->id,
                organizationId: $organization->id,
                projectId: $project->id,
                provider: IntegrationProvider::Notion,
                plaintextCredential: $existingCredential,
            );

        Http::fake([
            'https://api.notion.com/v1/users/me' => Http::response([
                'object' => 'error',
                'status' => 401,
                'code' => 'unauthorized',
                'message' => 'API token is invalid.',
                'request_id' => 'req-invalid-token',
            ], 401),
        ]);

        $this->actingAs($user)
            ->post($url, [
                'credential' => $invalidCandidate,
                'database_id' => 'd9824bdc-8445-4327-be8b-5b47500af6ce',
            ])
            ->assertSessionHasErrors('connection')
            ->assertDontSee($invalidCandidate);

        $storedCredential = ProviderCredential::query()
            ->forProject($project->id)
            ->where(
                'provider',
                IntegrationProvider::Notion->value,
            )
            ->firstOrFail();

        $decrypted = app(
            IntegrationCredentialCipher::class,
        )
            ->decrypt(
                $storedCredential->secret_ciphertext,
            )
            ->reveal();

        expect($decrypted)
            ->toBe($existingCredential)
            ->and($storedCredential->version)
            ->toBe(1);

        $auditMetadata = AuditEvent::query()
            ->where(
                'event_type',
                AuditEventType::NotionConnectionTestFailed
                    ->value,
            )
            ->firstOrFail()
            ->metadata;

        expect(json_encode(
            $auditMetadata,
            JSON_THROW_ON_ERROR,
        ))
            ->not->toContain($invalidCandidate)
            ->not->toContain($existingCredential);
    },
);

test(
    'members cannot test project integrations',
    function (): void {
        [
            'user' => $user,
            'url' => $url,
        ] = notionConnectionFixture(
            OrganizationRole::Member,
        );

        Http::fake();

        $this->actingAs($user)
            ->post($url, [
                'credential' => 'secret_notion_abcdefghijklmnopqrstuvwxyz',
                'database_id' => 'd9824bdc-8445-4327-be8b-5b47500af6ce',
            ])
            ->assertForbidden();

        Http::assertNothingSent();

        expect(ProjectIntegration::query()->count())
            ->toBe(0);
    },
);

/**
 * Create a project positioned at the Notion setup step.
 *
 * @return array{
 *     user: User,
 *     organization: Organization,
 *     project: Project,
 *     url: string
 * }
 */
function notionConnectionFixture(
    OrganizationRole $role,
): array {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    $membership = OrganizationMembership::factory()
        ->for($organization)
        ->for($user);

    match ($role) {
        OrganizationRole::Owner => $membership->owner()->create(),

        OrganizationRole::Administrator => $membership->administrator()->create(),

        /*
        * Member is the default OrganizationMembershipFactory role.
        */
        OrganizationRole::Member => $membership->create(),

        OrganizationRole::Viewer => $membership->viewer()->create(),
    };

    $project = Project::factory()
        ->for($organization)
        ->create();

    ProjectConfiguration::factory()
        ->for($project)
        ->create();

    ProjectSetupProgress::query()->create([
        'project_id' => $project->id,
        'current_step' => ProjectSetupStep::Integrations,
        'completed_steps' => [
            ProjectSetupStep::Details->value,
            ProjectSetupStep::Repository->value,
        ],
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
