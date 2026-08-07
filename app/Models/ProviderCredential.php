<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Integrations\IntegrationProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stores one encrypted external-provider credential for a project.
 *
 * The model never automatically decrypts the credential. Plaintext access must
 * go through IntegrationCredentialCipher at an authorized application boundary.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $project_id
 * @property IntegrationProvider $provider
 * @property string $secret_ciphertext
 * @property int $version
 * @property int|null $created_by_user_id
 * @property int|null $last_rotated_by_user_id
 * @property string|null $last_connection_status
 * @property string|null $last_connection_failure_code
 * @property string|null $last_provider_request_id
 * @property int|null $last_tested_by_user_id
 * @property int|null $verified_credential_version
 * @property CarbonImmutable|null $rotated_at
 * @property CarbonImmutable|null $last_tested_at
 * @property CarbonImmutable|null $last_connected_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'organization_id',
    'project_id',
    'provider',
    'secret_ciphertext',
    'version',
    'created_by_user_id',
    'last_rotated_by_user_id',
    'rotated_at',
    'last_connection_status',
    'last_connection_failure_code',
    'last_provider_request_id',
    'last_tested_by_user_id',
    'verified_credential_version',
    'last_tested_at',
    'last_connected_at',
])]
final class ProviderCredential extends Model
{
    /** @var list<string> */
    protected $hidden = ['secret_ciphertext'];

    /**
     * Return the organization that owns the credential.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Return the project that owns the credential.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Return the user that initially stored the credential.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Return the user that most recently rotated the credential.
     *
     * @return BelongsTo<User, $this>
     */
    public function lastRotatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_rotated_by_user_id');
    }

    /**
     * Return the user that most recently ran a persisted connection preflight.
     *
     * @return BelongsTo<User, $this>
     */
    public function lastTestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_tested_by_user_id');
    }

    /**
     * Limit credentials to one explicit organization.
     *
     * @param  Builder<ProviderCredential>  $query
     * @return Builder<ProviderCredential>
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where(
            $query->getModel()->qualifyColumn('organization_id'),
            $organizationId,
        );
    }

    /**
     * Limit credentials to one explicit project.
     *
     * @param  Builder<ProviderCredential>  $query
     * @return Builder<ProviderCredential>
     */
    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->where(
            $query->getModel()->qualifyColumn('project_id'),
            $projectId,
        );
    }

    /**
     * Return metadata that may safely be exposed to an authorized interface.
     *
     * The credential and ciphertext are deliberately absent.
     *
     * @return array<string, mixed>
     */
    public function toSafeMetadata(): array
    {
        return [
            'provider' => $this->provider->value,
            'configured' => true,
            'owner_scope' => 'project',
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'rotated_at' => $this->rotated_at?->toIso8601String(),
            'last_connection_status' => $this->last_connection_status,
            'last_connection_failure_code' => $this->last_connection_failure_code,
            'last_provider_request_id' => $this->last_provider_request_id,
            'verified_credential_version' => $this->verified_credential_version,
            'last_tested_at' => $this->last_tested_at?->toIso8601String(),
            'last_connected_at' => $this->last_connected_at?->toIso8601String(),
        ];
    }

    /**
     * Cast persisted values to domain-safe types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => IntegrationProvider::class,
            'version' => 'integer',
            'verified_credential_version' => 'integer',
            'rotated_at' => 'immutable_datetime',
            'last_tested_at' => 'immutable_datetime',
            'last_connected_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
