<?php

declare(strict_types=1);

namespace App\Application\Approvals\Handlers;

use App\Application\Approvals\Commands\DecideApproval;
use App\Application\Approvals\RecordApprovalLifecycleEvent;
use App\Application\Shared\Commands\CommandResult;
use App\Application\Shared\Contracts\TransactionManager;
use App\Domain\Approvals\ApprovalStatus;
use App\Models\Approval;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

/**
 * Applies an authorized terminal approval decision exactly once.
 */
final readonly class DecideApprovalHandler
{
    /**
     * Inject transaction and lifecycle-event services.
     */
    public function __construct(
        private TransactionManager $transactions,
        private RecordApprovalLifecycleEvent $events,
    ) {}

    /**
     * Authorize, lock, and decide one approval atomically.
     */
    public function handle(DecideApproval $command): CommandResult
    {
        if (! Str::isUlid($command->approvalId)) {
            throw new InvalidArgumentException(
                'The approval identifier must be a valid ULID.',
            );
        }

        if ($command->actorUserId < 1) {
            throw new InvalidArgumentException(
                'The approval decision actor identifier must be positive.',
            );
        }

        $decisionKey = $this->normalizeIdentifier(
            value: $command->idempotencyKey,
            name: 'approval decision idempotency',
            maximumLength: 191,
        );

        $correlationId = $this->normalizeIdentifier(
            value: $command->correlationId,
            name: 'approval correlation',
            maximumLength: 128,
        );

        $causationId = $this->normalizeOptionalIdentifier(
            value: $command->causationId,
            name: 'approval causation',
            maximumLength: 128,
        );

        $reason = $this->normalizeReason($command->reason);

        $fingerprint = $this->decisionFingerprint(
            command: $command,
            reason: $reason,
        );

        return $this->transactions->run(
            function () use (
                $command,
                $decisionKey,
                $correlationId,
                $causationId,
                $reason,
                $fingerprint,
            ): CommandResult {
                /** @var Approval $approval */
                $approval = Approval::query()
                    ->with('project')
                    ->whereKey($command->approvalId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $actor = User::query()->findOrFail(
                    $command->actorUserId,
                );

                Gate::forUser($actor)->authorize(
                    'approve',
                    $approval->project,
                );

                $decisionKeyCollision = Approval::query()
                    ->forProject($approval->project_id)
                    ->where(
                        'decision_idempotency_key',
                        $decisionKey,
                    )
                    ->where('id', '!=', $approval->id)
                    ->exists();

                if ($decisionKeyCollision) {
                    return CommandResult::conflict(
                        message: 'The approval decision idempotency key was already used for another approval.',
                        details: [
                            'approval_id' => $approval->id,
                        ],
                    );
                }

                $now = CarbonImmutable::now();

                if ($approval->isDue($now)) {
                    $approval->forceFill([
                        'status' => ApprovalStatus::Expired,
                    ])->save();

                    $this->events->expired(
                        approval: $approval,
                        correlationId: $correlationId,
                        causationId: $causationId,
                    );

                    return CommandResult::conflict(
                        message: 'The approval expired before the decision was applied.',
                        details: [
                            'approval_id' => $approval->id,
                            'status' => ApprovalStatus::Expired->value,
                        ],
                    );
                }

                if ($approval->status->isTerminal()) {
                    if ($this->isExactReplay(
                        approval: $approval,
                        command: $command,
                        decisionKey: $decisionKey,
                        fingerprint: $fingerprint,
                    )) {
                        return $this->successfulResult(
                            approval: $approval,
                            replayed: true,
                        );
                    }

                    return CommandResult::conflict(
                        message: 'The approval already has a different terminal outcome.',
                        details: [
                            'approval_id' => $approval->id,
                            'status' => $approval->status->value,
                        ],
                    );
                }

                $approval->forceFill([
                    'status' => $command->decision->status(),
                    'decided_by_user_id' => $actor->id,
                    'decision_idempotency_key' => $decisionKey,
                    'decision_fingerprint' => $fingerprint,
                    'decision_reason' => $reason,
                    'decided_at' => $now,
                ])->save();

                $this->events->decided(
                    approval: $approval,
                    actor: $actor,
                    decision: $command->decision,
                    correlationId: $correlationId,
                    causationId: $causationId,
                );

                return $this->successfulResult(
                    approval: $approval,
                    replayed: false,
                );
            },
        );
    }

    /**
     * Determine whether a terminal command is an exact safe replay.
     */
    private function isExactReplay(
        Approval $approval,
        DecideApproval $command,
        string $decisionKey,
        string $fingerprint,
    ): bool {
        return $approval->status === $command->decision->status()
            && $approval->decision_idempotency_key !== null
            && $approval->decision_fingerprint !== null
            && hash_equals(
                $approval->decision_idempotency_key,
                $decisionKey,
            )
            && hash_equals(
                $approval->decision_fingerprint,
                $fingerprint,
            );
    }

    /**
     * Normalize a bounded optional human decision reason.
     */
    private function normalizeReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $normalized = trim($reason);

        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized) > 2_000) {
            throw new InvalidArgumentException(
                'The approval decision reason may not exceed 2,000 characters.',
            );
        }

        return $normalized;
    }

    /**
     * Produce a stable fingerprint for idempotent decision replay.
     */
    private function decisionFingerprint(
        DecideApproval $command,
        ?string $reason,
    ): string {
        try {
            $material = json_encode(
                [
                    'approval_id' => $command->approvalId,
                    'actor_user_id' => $command->actorUserId,
                    'decision' => $command->decision->value,
                    'reason' => $reason,
                ],
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'The approval decision must be JSON serializable.',
                previous: $exception,
            );
        }

        return hash('sha256', $material);
    }

    /**
     * Build the stable successful decision result.
     */
    private function successfulResult(
        Approval $approval,
        bool $replayed,
    ): CommandResult {
        return CommandResult::succeeded([
            'approval_id' => $approval->id,
            'project_id' => $approval->project_id,
            'status' => $approval->status->value,
            'replayed' => $replayed,
        ]);
    }

    /**
     * Normalize a required command or trace identifier.
     */
    private function normalizeIdentifier(
        string $value,
        string $name,
        int $maximumLength,
    ): string {
        $normalized = trim($value);

        if (
            $normalized === ''
            || mb_strlen($normalized) > $maximumLength
            || preg_match(
                '/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/',
                $normalized,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                "The {$name} identifier is invalid.",
            );
        }

        return $normalized;
    }

    /**
     * Normalize an optional trace identifier.
     */
    private function normalizeOptionalIdentifier(
        ?string $value,
        string $name,
        int $maximumLength,
    ): ?string {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $this->normalizeIdentifier(
            value: $value,
            name: $name,
            maximumLength: $maximumLength,
        );
    }
}
