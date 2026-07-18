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
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property CarbonImmutable $status_changed_at
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
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
     * Limit a query to projects that have not been archived.
     *
     * This scope does not apply organization tenancy. AIOS-017 owns the
     * mandatory organization-scoping repository implementation.
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
     */
    public function archive(): void
    {
        $this->assertPersisted();

        DB::transaction(
            function (): void {
                /** @var self $project */
                $project = self::query()
                    ->whereKey($this->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($project->archived_at !== null) {
                    return;
                }

                /*
                 * archived_at is intentionally excluded from mass assignment.
                 * Only this guarded aggregate operation may change it.
                 */
                $project->forceFill([
                    'archived_at' => now(),
                ])->save();
            },
            attempts: 3,
        );

        $this->refresh();
    }

    /**
     * Restore an archived project under a database row lock.
     *
     * The operation is idempotent and does not change the workflow status.
     */
    public function restore(): void
    {
        $this->assertPersisted();

        DB::transaction(
            function (): void {
                /** @var self $project */
                $project = self::query()
                    ->whereKey($this->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($project->archived_at === null) {
                    return;
                }

                $project->forceFill([
                    'archived_at' => null,
                ])->save();
            },
            attempts: 3,
        );

        $this->refresh();
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
}
