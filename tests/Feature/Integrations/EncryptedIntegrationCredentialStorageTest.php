<?php

declare(strict_types=1);

use App\Application\Integrations\Contracts\IntegrationCredentialCipher;
use App\Domain\Audit\AuditEventType;
use App\Domain\Identity\OrganizationRole;
use App\Domain\Integrations\IntegrationProvider;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config()->set([
        'rate-limits.project_commands.credentials.per_minute' => 100,
        'rate-limits.project_commands.credentials.per_hour' => 1000,
    ]);

    Cache::store((string) config('cache.limiter'))->flush();
});

test('an owner stores an encrypted credential without redisplay', function (): void {
    [
        'user' => $user,
        'organization' => $organization,
        'project' => $project,
        'url' => $url,
    ] = integrationCredentialFixture(OrganizationRole::Owner);

    $plaintext = 'secret_notion_abcdefghijklmnopqrstuvwxyz';

    $this->actingAs($user)
        ->put($url, [
            'credential' => $plaintext,
        ])
        ->assertRedirect(route(
            'organizations.projects.show',
            [
                'organization' => $organization,
                'project' => $project,
            ],
        ))
        ->assertSessionHasNoErrors()
        ->assertDontSee($plaintext);

    $rawCredential = DB::table('provider_credentials')
        ->where('project_id', $project->id)
        ->where('provider', IntegrationProvider::Notion->value)
        ->first();

    expect($rawCredential)
        ->not->toBeNull();

    /** @var object{secret_ciphertext: string, version: int} $rawCredential */
    expect($rawCredential->secret_ciphertext)
        ->not->toBe($plaintext)
        ->and($rawCredential->secret_ciphertext)
        ->not->toContain($plaintext)
        ->and($rawCredential->version)
        ->toBe(1);

    $credential = ProviderCredential::query()
        ->where('project_id', $project->id)
        ->firstOrFail();

    $cipher = app(IntegrationCredentialCipher::class);

    expect(
        $cipher->decrypt($credential->secret_ciphertext)->reveal(),
    )
        ->toBe($plaintext)
        ->and($credential->toArray())
        ->not->toHaveKey('secret_ciphertext')
        ->and($credential->toSafeMetadata())
        ->not->toHaveKey('credential')
        ->and($credential->toSafeMetadata())
        ->not->toHaveKey('secret_ciphertext');

    $audit = AuditEvent::query()
        ->where(
            'event_type',
            AuditEventType::IntegrationCredentialStored->value,
        )
        ->where('project_id', $project->id)
        ->firstOrFail();

    $encodedAuditMetadata = json_encode(
        $audit->metadata,
        JSON_THROW_ON_ERROR,
    );

    expect($encodedAuditMetadata)
        ->not->toContain($plaintext)
        ->and($encodedAuditMetadata)
        ->not->toContain($rawCredential->secret_ciphertext);
});

test('storing the same credential is idempotent', function (): void {
    [
        'user' => $user,
        'project' => $project,
        'url' => $url,
    ] = integrationCredentialFixture(OrganizationRole::Owner);

    $plaintext = 'secret_notion_abcdefghijklmnopqrstuvwxyz';

    $this->actingAs($user)
        ->put($url, ['credential' => $plaintext])
        ->assertSessionHasNoErrors();

    $firstCiphertext = ProviderCredential::query()
        ->where('project_id', $project->id)
        ->firstOrFail()
        ->secret_ciphertext;

    $this->put($url, ['credential' => $plaintext])
        ->assertSessionHasNoErrors();

    $credential = ProviderCredential::query()
        ->where('project_id', $project->id)
        ->firstOrFail();

    expect(ProviderCredential::query()->count())
        ->toBe(1)
        ->and($credential->version)
        ->toBe(1)
        ->and($credential->secret_ciphertext)
        ->toBe($firstCiphertext)
        ->and($credential->rotated_at)
        ->toBeNull()
        ->and(
            AuditEvent::query()
                ->where(
                    'event_type',
                    AuditEventType::IntegrationCredentialStored->value,
                )
                ->count(),
        )
        ->toBe(1)
        ->and(
            AuditEvent::query()
                ->where(
                    'event_type',
                    AuditEventType::IntegrationCredentialRotated->value,
                )
                ->count(),
        )
        ->toBe(0);
});

test('a different credential rotates the encrypted value', function (): void {
    [
        'user' => $user,
        'project' => $project,
        'url' => $url,
    ] = integrationCredentialFixture(OrganizationRole::Administrator);

    $firstPlaintext = 'secret_notion_first_abcdefghijklmnopqrstuvwxyz';
    $secondPlaintext = 'secret_notion_second_zyxwvutsrqponmlkjihgfedcba';

    $this->actingAs($user)
        ->put($url, ['credential' => $firstPlaintext])
        ->assertSessionHasNoErrors();

    $firstCiphertext = ProviderCredential::query()
        ->where('project_id', $project->id)
        ->firstOrFail()
        ->secret_ciphertext;

    $this->put($url, ['credential' => $secondPlaintext])
        ->assertSessionHasNoErrors();

    $credential = ProviderCredential::query()
        ->where('project_id', $project->id)
        ->firstOrFail();

    $decrypted = app(IntegrationCredentialCipher::class)
        ->decrypt($credential->secret_ciphertext)
        ->reveal();

    expect($credential->version)
        ->toBe(2)
        ->and($credential->secret_ciphertext)
        ->not->toBe($firstCiphertext)
        ->and($credential->rotated_at)
        ->not->toBeNull()
        ->and($credential->last_rotated_by_user_id)
        ->toBe($user->id)
        ->and($decrypted)
        ->toBe($secondPlaintext)
        ->and(
            AuditEvent::query()
                ->where(
                    'event_type',
                    AuditEventType::IntegrationCredentialRotated->value,
                )
                ->count(),
        )
        ->toBe(1);
});

test(
    'members and viewers cannot store integration credentials',
    function (OrganizationRole $role): void {
        [
            'user' => $user,
            'url' => $url,
        ] = integrationCredentialFixture($role);

        $this->actingAs($user)
            ->put($url, [
                'credential' => 'secret_notion_abcdefghijklmnopqrstuvwxyz',
            ])
            ->assertForbidden();

        expect(ProviderCredential::query()->count())
            ->toBe(0)
            ->and(
                AuditEvent::query()
                    ->whereIn('event_type', [
                        AuditEventType::IntegrationCredentialStored->value,
                        AuditEventType::IntegrationCredentialRotated->value,
                    ])
                    ->count(),
            )
            ->toBe(0);
    },
)->with([
    'member' => [OrganizationRole::Member],
    'viewer' => [OrganizationRole::Viewer],
]);

test('a project cannot be addressed through another organization', function (): void {
    [
        'user' => $user,
        'project' => $project,
    ] = integrationCredentialFixture(OrganizationRole::Owner);

    $foreignOrganization = Organization::factory()->create();

    OrganizationMembership::factory()
        ->for($foreignOrganization)
        ->for($user)
        ->owner()
        ->create();

    $url = route(
        'organizations.projects.integrations.credentials.store',
        [
            'organization' => $foreignOrganization,
            'project' => $project,
            'provider' => IntegrationProvider::Notion,
        ],
    );

    $this->actingAs($user)
        ->put($url, [
            'credential' => 'secret_notion_abcdefghijklmnopqrstuvwxyz',
        ])
        ->assertNotFound();

    expect(ProviderCredential::query()->count())
        ->toBe(0);
});

test('invalid credentials do not create persistent state', function (): void {
    [
        'user' => $user,
        'organization' => $organization,
        'project' => $project,
        'url' => $url,
    ] = integrationCredentialFixture(OrganizationRole::Owner);

    $projectUrl = route(
        'organizations.projects.show',
        [
            'organization' => $organization,
            'project' => $project,
        ],
    );

    $this->actingAs($user)
        ->from($projectUrl)
        ->put($url, [
            'credential' => "secret_notion_abc\ninvalid",
        ])
        ->assertRedirect($projectUrl)
        ->assertSessionHasErrors('credential');

    expect(ProviderCredential::query()->count())
        ->toBe(0);
});

test('credential rate limiting does not rotate persistent state', function (): void {
    config()->set([
        'rate-limits.project_commands.credentials.per_minute' => 1,
        'rate-limits.project_commands.credentials.per_hour' => 100,
    ]);

    Cache::store((string) config('cache.limiter'))->flush();

    [
        'user' => $user,
        'organization' => $organization,
        'project' => $project,
        'url' => $url,
    ] = integrationCredentialFixture(OrganizationRole::Owner);

    $projectUrl = route(
        'organizations.projects.show',
        [
            'organization' => $organization,
            'project' => $project,
        ],
    );

    $firstPlaintext = 'secret_notion_first_abcdefghijklmnopqrstuvwxyz';
    $secondPlaintext = 'secret_notion_second_zyxwvutsrqponmlkjihgfedcba';

    /*
     * The first credential write consumes the configured limiter allowance.
     */
    $this->actingAs($user)
        ->from($projectUrl)
        ->put($url, [
            'credential' => $firstPlaintext,
        ])
        ->assertRedirect($projectUrl)
        ->assertSessionHasNoErrors();

    /*
     * Browser requests use the redirect-based rate-limit contract. They do not
     * return a JSON 429 response.
     */
    $response = $this
        ->from($projectUrl)
        ->put($url, [
            'credential' => $secondPlaintext,
        ]);

    $response
        ->assertRedirect($projectUrl)
        ->assertHeader('Retry-After')
        ->assertSessionHasErrors('rate_limit')
        /*
         * A rejected credential must not be persisted as session old input.
         */
        ->assertSessionMissingInput('credential');

    $credential = ProviderCredential::query()
        ->where('project_id', $project->id)
        ->where('provider', IntegrationProvider::Notion->value)
        ->firstOrFail();

    $decrypted = app(IntegrationCredentialCipher::class)
        ->decrypt($credential->secret_ciphertext)
        ->reveal();

    expect($credential->version)
        ->toBe(1)
        ->and($credential->rotated_at)
        ->toBeNull()
        ->and($credential->last_rotated_by_user_id)
        ->toBeNull()
        ->and($decrypted)
        ->toBe($firstPlaintext)
        ->and($decrypted)
        ->not->toBe($secondPlaintext)
        ->and(
            AuditEvent::query()
                ->where(
                    'event_type',
                    AuditEventType::IntegrationCredentialRotated->value,
                )
                ->where('project_id', $project->id)
                ->count(),
        )
        ->toBe(0);
});

test('credential rate limiting returns the json error contract', function (): void {
    config()->set([
        'rate-limits.project_commands.credentials.per_minute' => 1,
        'rate-limits.project_commands.credentials.per_hour' => 100,
    ]);

    Cache::store((string) config('cache.limiter'))->flush();

    [
        'user' => $user,
        'project' => $project,
        'url' => $url,
    ] = integrationCredentialFixture(OrganizationRole::Owner);

    $this->actingAs($user)
        ->putJson($url, [
            'credential' => 'secret_notion_first_abcdefghijklmnopqrstuvwxyz',
        ])
        ->assertRedirect();

    $response = $this->putJson($url, [
        'credential' => 'secret_notion_second_zyxwvutsrqponmlkjihgfedcba',
    ]);

    $response
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJson([
            'error' => [
                'code' => 'rate_limit_exceeded',
                'message' => 'Too many requests. Please retry later.',
                'retryable' => true,
            ],
        ]);

    $credential = ProviderCredential::query()
        ->where('project_id', $project->id)
        ->firstOrFail();

    expect($credential->version)
        ->toBe(1);
});

/**
 * Create an organization-scoped project integration fixture.
 *
 * @return array{
 *     user: User,
 *     organization: Organization,
 *     project: Project,
 *     url: string
 * }
 */
function integrationCredentialFixture(
    OrganizationRole $role,
): array {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    /** @var Factory<OrganizationMembership> $membershipFactory */
    $membershipFactory = OrganizationMembership::factory()
        ->for($organization)
        ->for($user);

    $membershipFactory = match ($role) {
        OrganizationRole::Owner => $membershipFactory->owner(),
        OrganizationRole::Administrator => $membershipFactory->administrator(),
        OrganizationRole::Member => $membershipFactory,
        OrganizationRole::Viewer => $membershipFactory->viewer(),
    };

    $membershipFactory->create();

    $project = Project::factory()
        ->for($organization)
        ->create();

    return [
        'user' => $user,
        'organization' => $organization,
        'project' => $project,
        'url' => route(
            'organizations.projects.integrations.credentials.store',
            [
                'organization' => $organization,
                'project' => $project,
                'provider' => IntegrationProvider::Notion,
            ],
        ),
    ];
}
