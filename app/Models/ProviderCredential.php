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
 * @property CarbonImmutable|null $rotated_at
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
])]
final class ProviderCredential extends Model
{
    /**
     * Prevent ciphertext from appearing in arrays, JSON, Inertia props, logs,
     * exception context, or accidental API responses.
     *
     * @var list<string>
     */
    protected $hidden = [
        'secret_ciphertext',
    ];

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
        return $this->belongsTo(
            User::class,
            'created_by_user_id',
        );
    }

    /**
     * Return the user that most recently rotated the credential.
     *
     * @return BelongsTo<User, $this>
     */
    public function lastRotatedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'last_rotated_by_user_id',
        );
    }

    /**
     * Limit credentials to one explicit organization.
     *
     * @param  Builder<ProviderCredential>  $query
     * @return Builder<ProviderCredential>
     */
    public function scopeForOrganization(
        Builder $query,
        int $organizationId,
    ): Builder {
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
    public function scopeForProject(
        Builder $query,
        int $projectId,
    ): Builder {
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
     * @return array{
     *     provider: string,
     *     configured: true,
     *     version: int,
     *     created_at: string|null,
     *     rotated_at: string|null
     * }
     */
    public function toSafeMetadata(): array
    {
        return [
            'provider' => $this->provider->value,
            'configured' => true,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'rotated_at' => $this->rotated_at?->toIso8601String(),
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
            'rotated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
