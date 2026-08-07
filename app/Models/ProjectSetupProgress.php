<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Projects\ProjectSetupStep;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persists resumable wizard progress for one project.
 *
 * @property int $id
 * @property int $project_id
 * @property ProjectSetupStep $current_step
 * @property list<string> $completed_steps
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'project_id',
    'current_step',
    'completed_steps',
    'completed_at',
])]
final class ProjectSetupProgress extends Model
{
    /**
     * Progress is intentionally stored in a singularly named table.
     *
     * @var string
     */
    protected $table = 'project_setup_progress';

    /**
     * Return the project whose setup progress is tracked.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Determine whether the specified step has been completed.
     */
    public function hasCompleted(ProjectSetupStep $step): bool
    {
        return in_array(
            $step->value,
            $this->completed_steps,
            true,
        );
    }

    /**
     * Determine whether the complete wizard has been confirmed.
     */
    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * Cast persisted values into domain-safe types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_step' => ProjectSetupStep::class,
            'completed_steps' => 'array',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
