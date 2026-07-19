<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Projects\ProjectLifecycle;
use App\Domain\Projects\ProjectStatus;
use App\Domain\Projects\ProjectType;
use App\Policies\ProjectPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Persistence aggregate root for an organization-owned project.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property ProjectType $project_type
 * @property ProjectStatus $status
 * @property-read ProjectConfiguration|null $configuration
 * @property CarbonImmutable $status_changed_at
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, ProviderCredential> $providerCredentials
 * @property-read Collection<int, ProjectIntegration> $projectIntegrations
 */
#[Fillable(['name', 'slug', 'description', 'project_type'])]
#[UsePolicy(ProjectPolicy::class)]
final class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * Define aggregate defaults before persistence.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    /**
     * Return the organization that owns this project.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Return the resumable project setup progress.
     *
     * @return HasOne<ProjectSetupProgress, $this>
     */
    public function setupProgress(): HasOne
    {
        return $this->hasOne(ProjectSetupProgress::class);
    }

    /**
     * Limit a query to projects owned by one explicit organization.
     *
     * Tenant context must always be supplied by the caller. This prevents
     * persistence code from relying on session state or another ambient value
     * that may not exist in console commands, queue workers, or tests.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
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
     * Limit a query to projects that have not been archived.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeUnarchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Limit a query to administratively archived projects.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    /**
     * Determine whether this project is archived.
     */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Archive the project under a database row lock.
     *
     * The operation is idempotent. Repeating it does not change the workflow
     * status or create another state transition.
     *
     * @return bool True when archived_at changed from null to a timestamp.
     */
    public function archive(): bool
    {
        $this->assertPersisted();

        $changed = DB::transaction(
            function (): bool {
                /** @var self $project */
                $project = self::query()
                    ->forOrganization($this->organization_id)
                    ->whereKey($this->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($project->archived_at !== null) {
                    return false;
                }

                /*
                * archived_at is intentionally excluded from mass assignment.
                * Only this guarded aggregate operation may change it.
                */
                $project->forceFill([
                    'archived_at' => now(),
                ])->save();

                return true;
            },
            attempts: 3,
        );

        $this->refresh();

        return $changed;
    }

    /**
     * Restore an archived project under a database row lock.
     *
     * The operation is idempotent and does not change the workflow status.
     *
     * @return bool True when archived_at changed from a timestamp to null.
     */
    public function restore(): bool
    {
        $this->assertPersisted();

        $changed = DB::transaction(
            function (): bool {
                /** @var self $project */
                $project = self::query()
                    ->forOrganization($this->organization_id)
                    ->whereKey($this->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($project->archived_at === null) {
                    return false;
                }

                $project->forceFill([
                    'archived_at' => null,
                ])->save();

                return true;
            },
            attempts: 3,
        );

        $this->refresh();

        return $changed;
    }

    /**
     * Transition the project workflow state under a database row lock.
     *
     * The row lock serializes competing transitions. Both requests therefore
     * cannot validate the same stale state and overwrite each other.
     */
    public function transitionTo(ProjectStatus $target): void
    {
        $this->assertPersisted();

        DB::transaction(
            function () use ($target): void {
                /** @var self $project */
                $project = self::query()
                    ->forOrganization($this->organization_id)
                    ->whereKey($this->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                (new ProjectLifecycle)->assertCanTransition(
                    from: $project->status,
                    to: $target,
                );

                /*
                 * Status is intentionally excluded from mass assignment.
                 * forceFill is permitted only after the domain guard succeeds.
                 */
                $project->forceFill([
                    'status' => $target,
                    'status_changed_at' => now(),
                ])->save();
            },
            attempts: 3,
        );

        $this->refresh();
    }

    /**
     * Bind project routes using the stable public slug.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Ensure aggregate operations are not executed against an unsaved model.
     */
    private function assertPersisted(): void
    {
        if (! $this->exists) {
            throw new LogicException(
                'A project must be persisted before this operation can run.',
            );
        }
    }

    /**
     * Cast persisted values to domain-safe types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'project_type' => ProjectType::class,
            'status' => ProjectStatus::class,
            'status_changed_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /**
     * Return the current versioned configuration for this project.
     *
     * @return HasOne<ProjectConfiguration, $this>
     */
    public function configuration(): HasOne
    {
        return $this->hasOne(ProjectConfiguration::class);
    }

    /**
     * Return encrypted provider credentials owned by this project.
     *
     * @return HasMany<ProviderCredential, $this>
     */
    public function providerCredentials(): HasMany
    {
        return $this->hasMany(ProviderCredential::class);
    }

    /**
     * Return safe provider integration metadata owned by this project.
     *
     * @return HasMany<ProjectIntegration, $this>
     */
    public function projectIntegrations(): HasMany
    {
        return $this->hasMany(ProjectIntegration::class);
    }
}
