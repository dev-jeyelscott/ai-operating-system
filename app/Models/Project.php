<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Projects\ProjectLifecycle;
use App\Domain\Projects\ProjectStatus;
use App\Domain\Projects\ProjectType;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'slug', 'description', 'project_type'])]
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
     * Transition the project under a database row lock.
     *
     * The row lock serializes competing transitions. Both requests therefore
     * cannot validate the same stale state and overwrite each other.
     */
    public function transitionTo(ProjectStatus $target): void
    {
        if (! $this->exists) {
            throw new LogicException(
                'A project must be persisted before its status can transition.',
            );
        }

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
        ];
    }
}
