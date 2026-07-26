<?php

declare(strict_types=1);

namespace App\Application\Approvals;

use App\Application\Shared\Contracts\TransactionManager;
use App\Domain\Approvals\ApprovalStatus;
use App\Models\Approval;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Expires a bounded batch of due approvals without duplicate transitions.
 */
final readonly class ExpireDueApprovals
{
    /**
     * Inject transaction and lifecycle-event services.
     */
    public function __construct(
        private TransactionManager $transactions,
        private RecordApprovalLifecycleEvent $events,
    ) {}

    /**
     * Expire pending approvals that reached their configured deadline.
     */
    public function handle(
        ?CarbonImmutable $at = null,
        int $limit = 500,
    ): int {
        if ($limit < 1 || $limit > 1_000) {
            throw new InvalidArgumentException(
                'The approval expiry batch limit must be between 1 and 1,000.',
            );
        }

        $expiryTime = $at ?? CarbonImmutable::now();
        $correlationId = (string) Str::ulid();

        /** @var list<string> $approvalIds */
        $approvalIds = Approval::query()
            ->due($expiryTime)
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        $expiredCount = 0;

        foreach ($approvalIds as $approvalId) {
            $expired = $this->transactions->run(
                function () use (
                    $approvalId,
                    $expiryTime,
                    $correlationId,
                ): bool {
                    /** @var Approval|null $approval */
                    $approval = Approval::query()
                        ->with('project')
                        ->whereKey($approvalId)
                        ->lockForUpdate()
                        ->first();

                    if (
                        $approval === null
                        || ! $approval->isDue($expiryTime)
                    ) {
                        return false;
                    }

                    $approval->forceFill([
                        'status' => ApprovalStatus::Expired,
                    ])->save();

                    $this->events->expired(
                        approval: $approval,
                        correlationId: $correlationId,
                        causationId: null,
                    );

                    return true;
                },
            );

            if ($expired) {
                $expiredCount++;
            }
        }

        return $expiredCount;
    }
}
