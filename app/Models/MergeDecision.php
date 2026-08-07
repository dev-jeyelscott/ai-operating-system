<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\QualityAssurance\MergeDecisionAction;
use App\Domain\Tickets\TicketStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Stores one append-only authorized simulated merge disposition.
 *
 * Escalation and deferral records may precede one terminal approval or
 * changes-requested record. Existing history is never edited or deleted.
 *
 * @property string $id
 * @property int $project_id
 * @property int $roadmap_task_id
 * @property string $qa_assessment_id
 * @property int $actor_user_id
 * @property MergeDecisionAction $action
 * @property string|null $reason
 * @property string $idempotency_key_hash
 * @property string $request_fingerprint
 * @property string $correlation_id
 * @property string|null $causation_id
 * @property string $assessment_decision
 * @property string $assessment_fingerprint
 * @property string $ticket_status_before
 * @property string $ticket_status_after
 * @property string|null $terminal_marker
 * @property bool $simulated
 * @property string $actual_state
 * @property CarbonImmutable $decided_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[DateFormat('Y-m-d H:i:s.u')]
final class MergeDecision extends Model
{
    use HasUlids;

    /**
     * Permit controlled creation through the application command.
     *
     * Updates and deletions remain rejected through the model lifecycle hooks.
     *
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * Prevent mutation or deletion of material human-decision history.
     */
    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new LogicException(
                'Merge decision history cannot be updated.',
            );
        });

        self::deleting(static function (): void {
            throw new LogicException(
                'Merge decision history cannot be deleted.',
            );
        });
    }

    /**
     * Scope decisions to an explicit project boundary.
     *
     * @param  Builder<MergeDecision>  $query
     * @return Builder<MergeDecision>
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
     * Scope terminal request-changes decisions that authorize another
     * simulated Layer 2 execution for the affected ticket.
     *
     * @param  Builder<MergeDecision>  $query
     * @return Builder<MergeDecision>
     */
    public function scopeAuthorizesChangesRequestedRework(
        Builder $query,
    ): Builder {
        $model = $query->getModel();

        return $query
            ->where(
                $model->qualifyColumn('action'),
                MergeDecisionAction::RequestChanges->value,
            )
            ->where(
                $model->qualifyColumn('ticket_status_before'),
                TicketStatus::ForQa->value,
            )
            ->where(
                $model->qualifyColumn('ticket_status_after'),
                TicketStatus::ChangesRequested->value,
            )
            ->where(
                $model->qualifyColumn('terminal_marker'),
                'T',
            )
            ->where(
                $model->qualifyColumn('simulated'),
                true,
            );
    }

    /**
     * Cast persisted values into stable domain and PHP types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'roadmap_task_id' => 'integer',
            'actor_user_id' => 'integer',
            'action' => MergeDecisionAction::class,
            'simulated' => 'boolean',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
