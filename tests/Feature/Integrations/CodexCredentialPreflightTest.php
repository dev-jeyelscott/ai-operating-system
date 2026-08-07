<?php

declare(strict_types=1);

use App\Application\Integrations\CodexConnectionTestResult;
use App\Application\Integrations\Contracts\CodexConnectionGateway;
use App\Application\Integrations\Contracts\IntegrationCredentialCipher;
use App\Application\Integrations\SaveProjectIntegrationCredential;
use App\Application\Integrations\TestProjectCodexConnection;
use App\Domain\Identity\OrganizationRole;
use App\Domain\Integrations\CodexConnectionFailureCode;
use App\Domain\Integrations\IntegrationCredentialSecret;
use App\Domain\Integrations\IntegrationProvider;
use App\Models\AuditEvent;
use App\Models\NotificationEvent;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Create one owner, project, and complete configuration fixture for Codex tests.
 *
 * @return array{0: User, 1: Organization, 2: Project, 3: ProjectConfiguration}
 */
function aios243CodexFixture(): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    OrganizationMembership::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role' => OrganizationRole::Owner,
    ]);

    $project = Project::factory()->create([
        'organization_id' => $organization->id,
    ]);

    $configuration = ProjectConfiguration::factory()
        ->complete()
        ->create([
            'project_id' => $project->id,
        ]);

    return [$user, $organization, $project, $configuration];
}

it('stores a new codex credential only after successful server side preflight', function (): void {
    [$user, $organization, $project] = aios243CodexFixture();
    $plaintext = 'sk-test-aios243-credential-value-123456789';

    app()->instance(
        CodexConnectionGateway::class,
        new class implements CodexConnectionGateway
        {
            /**
             * Return a deterministic successful read-only model preflight.
             */
            public function test(
                IntegrationCredentialSecret $credential,
                string $modelIdentifier,
            ): CodexConnectionTestResult {
                unset($credential);

                return CodexConnectionTestResult::connected(
                    modelIdentifier: $modelIdentifier,
                    providerRequestId: 'req_aios243_safe',
                );
            }
        },
    );

    $result = app(TestProjectCodexConnection::class)->handle(
        actorUserId: $user->id,
        organizationId: $organization->id,
        projectId: $project->id,
        plaintextCredential: $plaintext,
        correlationId: 'aios243-success',
    );

    $credential = ProviderCredential::query()
        ->where('project_id', $project->id)
        ->where('provider', IntegrationProvider::Codex->value)
        ->firstOrFail();

    expect($result->successful)->toBeTrue()
        ->and($credential->secret_ciphertext)->not->toBe($plaintext)
        ->and($credential->last_connection_status)->toBe('connected')
        ->and($credential->verified_credential_version)->toBe($credential->version)
        ->and($credential->last_provider_request_id)->toBe('req_aios243_safe');

    $decrypted = app(IntegrationCredentialCipher::class)
        ->decrypt($credential->secret_ciphertext);

    expect($decrypted->reveal())->toBe($plaintext);

    $auditPayload = AuditEvent::query()
        ->where('project_id', $project->id)
        ->get()
        ->toJson();
    $notificationPayload = NotificationEvent::query()
        ->where('project_id', $project->id)
        ->get()
        ->toJson();

    expect($auditPayload)->not->toContain($plaintext)
        ->and($notificationPayload)->not->toContain($plaintext);
});

it('does not replace an existing credential when a new candidate fails preflight', function (): void {
    [$user, $organization, $project] = aios243CodexFixture();
    $existing = 'sk-test-aios243-existing-credential-123456';
    $candidate = 'sk-test-aios243-invalid-candidate-654321';

    $stored = app(SaveProjectIntegrationCredential::class)
        ->handle(
            actorUserId: $user->id,
            organizationId: $organization->id,
            projectId: $project->id,
            provider: IntegrationProvider::Codex,
            plaintextCredential: $existing,
            correlationId: 'aios243-existing',
        );

    app()->instance(
        CodexConnectionGateway::class,
        new class implements CodexConnectionGateway
        {
            /**
             * Return a sanitized provider rejection without response content.
             */
            public function test(
                IntegrationCredentialSecret $credential,
                string $modelIdentifier,
            ): CodexConnectionTestResult {
                unset($credential);

                return CodexConnectionTestResult::failed(
                    failureCode: CodexConnectionFailureCode::InvalidCredential,
                    modelIdentifier: $modelIdentifier,
                    providerRequestId: 'req_aios243_rejected',
                );
            }
        },
    );

    $result = app(TestProjectCodexConnection::class)->handle(
        actorUserId: $user->id,
        organizationId: $organization->id,
        projectId: $project->id,
        plaintextCredential: $candidate,
        correlationId: 'aios243-failure',
    );

    $after = ProviderCredential::query()->findOrFail($stored->id);
    $decrypted = app(IntegrationCredentialCipher::class)
        ->decrypt($after->secret_ciphertext);

    expect($result->successful)->toBeFalse()
        ->and($result->failureCode)
        ->toBe(CodexConnectionFailureCode::InvalidCredential)
        ->and($after->version)->toBe($stored->version)
        ->and($decrypted->reveal())->toBe($existing)
        ->and($decrypted->reveal())->not->toBe($candidate);
});

it('does not expose credential material through safe model metadata', function (): void {
    [$user, $organization, $project] = aios243CodexFixture();
    $plaintext = 'sk-test-aios243-safe-metadata-123456789';

    $credential = app(SaveProjectIntegrationCredential::class)
        ->handle(
            actorUserId: $user->id,
            organizationId: $organization->id,
            projectId: $project->id,
            provider: IntegrationProvider::Codex,
            plaintextCredential: $plaintext,
        );

    $safe = json_encode(
        $credential->toSafeMetadata(),
        JSON_THROW_ON_ERROR,
    );

    expect($safe)->not->toContain($plaintext)
        ->and($safe)->not->toContain('secret_ciphertext')
        ->and($safe)->not->toContain('authorization');
});
