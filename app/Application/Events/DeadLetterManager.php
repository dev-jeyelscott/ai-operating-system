<?php

declare(strict_types=1);

namespace App\Application\Events;

use App\Application\Audit\RecordAuditEvent;
use App\Application\Events\Contracts\OutboxTransport;
use App\Application\Events\Data\DeadLetterRecord;
use App\Application\Security\RedactSensitiveData;
use App\Application\Shared\Contracts\TransactionManager;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Events\DeadLetterSource;
use App\Jobs\ConsumeOutboxMessage;
use App\Models\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Lists and safely replays terminal domain-event delivery failures.
 */
final readonly class DeadLetterManager
{
    private const MAX_LIST_LIMIT = 100;

    private const MAX_QUEUE_SCAN = 500;

    private const MAX_ACTOR_ID_LENGTH = 191;

    private const MAX_REASON_LENGTH = 1000;

    /**
     * Inject transaction, queue failure, transport, and audit services.
     */
    public function __construct(
        private TransactionManager $transactions,
        private FailedJobProviderInterface $failedJobs,
        private OutboxTransport $transport,
        private RecordAuditEvent $audit,
        private RedactSensitiveData $redactor,
    ) {}

    /**
     * Return the newest safe dead-letter records from one or both sources.
     *
     * @return list<DeadLetterRecord>
     */
    public function inspect(
        ?DeadLetterSource $source = null,
        int $limit = 50,
    ): array {
        return $this->inspectScoped(
            source: $source,
            limit: $limit,
            organizationId: null,
            projectId: null,
        );
    }

    /**
     * Return dead letters owned by one explicit organization and project.
     *
     * This method is the only dead-letter query that project-facing HTTP
     * interfaces should use.
     *
     * @return list<DeadLetterRecord>
     */
    public function inspectForProject(
        int $organizationId,
        int $projectId,
        ?DeadLetterSource $source = null,
        int $limit = 50,
    ): array {
        if ($organizationId < 1 || $projectId < 1) {
            throw new InvalidArgumentException(
                'Organization and project identifiers must be positive integers.',
            );
        }

        return $this->inspectScoped(
            source: $source,
            limit: $limit,
            organizationId: $organizationId,
            projectId: $projectId,
        );
    }

    /**
     * Return a bounded and optionally tenant-scoped dead-letter list.
     *
     * @return list<DeadLetterRecord>
     */
    private function inspectScoped(
        ?DeadLetterSource $source,
        int $limit,
        ?int $organizationId,
        ?int $projectId,
    ): array {
        if ($limit < 1 || $limit > self::MAX_LIST_LIMIT) {
            throw new InvalidArgumentException(
                'The dead-letter list limit must be between 1 and 100.',
            );
        }

        /** @var list<DeadLetterRecord> $records */
        $records = [];

        if (
            $source === null
            || $source === DeadLetterSource::Outbox
        ) {
            $records = array_merge(
                $records,
                $this->listOutboxDeadLetters(
                    limit: $limit,
                    organizationId: $organizationId,
                    projectId: $projectId,
                ),
            );
        }

        if (
            $source === null
            || $source === DeadLetterSource::Queue
        ) {
            $records = array_merge(
                $records,
                $this->listQueueDeadLetters(
                    limit: $limit,
                    organizationId: $organizationId,
                    projectId: $projectId,
                ),
            );
        }

        usort(
            $records,
            static function (
                DeadLetterRecord $left,
                DeadLetterRecord $right,
            ): int {
                $timestampComparison =
                    $right->failedAt->getTimestamp()
                    <=> $left->failedAt->getTimestamp();

                return $timestampComparison !== 0
                    ? $timestampComparison
                    : strcmp($left->id, $right->id);
            },
        );

        return array_slice($records, 0, $limit);
    }

    /**
     * Replay exactly one allowlisted dead letter.
     */
    public function replay(
        DeadLetterSource $source,
        string $identifier,
        string $actorId,
        string $reason,
    ): DeadLetterRecord {
        $normalizedIdentifier = $this->normalizeRequiredValue(
            value: $identifier,
            name: 'dead-letter identifier',
            maximumLength: 191,
        );

        $normalizedActorId = $this->normalizeActorId($actorId);
        $normalizedReason = $this->normalizeRequiredValue(
            value: $reason,
            name: 'replay reason',
            maximumLength: self::MAX_REASON_LENGTH,
        );

        return match ($source) {
            DeadLetterSource::Outbox => $this->replayOutboxDeadLetter(
                eventId: $normalizedIdentifier,
                actorId: $normalizedActorId,
                reason: $normalizedReason,
            ),
            DeadLetterSource::Queue => $this->replayQueueDeadLetter(
                failedJobId: $normalizedIdentifier,
                actorId: $normalizedActorId,
                reason: $normalizedReason,
            ),
        };
    }

    /**
     * Read explicit outbox dead-letter rows without exposing their envelopes.
     *
     * @return list<DeadLetterRecord>
     */
    private function listOutboxDeadLetters(
        int $limit,
        ?int $organizationId = null,
        ?int $projectId = null,
    ): array {
        /** @var list<DeadLetterRecord> $records */
        $records = [];

        $query = OutboxMessage::query()
            ->whereNull('published_at')
            ->whereNotNull('dead_lettered_at');

        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }

        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        $messages = $query
            ->orderByDesc('dead_lettered_at')
            ->limit($limit)
            ->get();

        foreach ($messages as $message) {
            $records[] = new DeadLetterRecord(
                source: DeadLetterSource::Outbox,
                id: $message->event_id,
                eventId: $message->event_id,
                eventName: $message->event_name,
                attempts: $message->dispatch_attempts,
                failedAt: $this->deadLetteredAt($message),
                errorType: $this->errorType(
                    $message->last_error,
                ),
            );
        }

        return $records;
    }

    /**
     * Read only allowlisted failed consumer jobs.
     *
     * Arbitrary Laravel jobs are intentionally omitted because replaying an
     * unknown serialized command could repeat a non-idempotent side effect.
     *
     * @return list<DeadLetterRecord>
     */
    private function listQueueDeadLetters(
        int $limit,
        ?int $organizationId = null,
        ?int $projectId = null,
    ): array {
        /** @var list<DeadLetterRecord> $records */
        $records = [];

        $scanLimit = min(
            self::MAX_QUEUE_SCAN,
            max($limit, $limit * 5),
        );

        foreach (
            array_slice(
                $this->failedJobs->all(),
                0,
                $scanLimit,
            ) as $failedJob
        ) {
            if (! is_object($failedJob)) {
                continue;
            }

            $attributes = (array) $failedJob;

            try {
                $payload = $this->decodePayload(
                    $attributes['payload'] ?? null,
                );

                $job = $this->extractConsumeOutboxMessage(
                    $payload,
                );
            } catch (Throwable) {
                continue;
            }

            $message = OutboxMessage::query()
                ->where('event_id', $job->eventId)
                ->first();

            if (
                ($organizationId !== null || $projectId !== null)
                && $message === null
            ) {
                continue;
            }

            if (
                $organizationId !== null
                && $message?->organization_id !== $organizationId
            ) {
                continue;
            }

            if (
                $projectId !== null
                && $message?->project_id !== $projectId
            ) {
                continue;
            }

            $failedAt = CarbonImmutable::parse(
                (string) ($attributes['failed_at'] ?? 'now'),
            );

            $records[] = new DeadLetterRecord(
                source: DeadLetterSource::Queue,
                id: (string) ($attributes['id'] ?? ''),
                eventId: $job->eventId,
                eventName: $message?->event_name,
                attempts: (int) ($payload['attempts'] ?? 0),
                failedAt: $failedAt,
                errorType: $this->errorType(
                    is_string($attributes['exception'] ?? null)
                        ? $attributes['exception']
                        : null,
                ),
            );

            if (count($records) >= $limit) {
                break;
            }
        }

        return $records;
    }

    /**
     * Reset one explicit outbox dead letter for a new bounded dispatch cycle.
     */
    private function replayOutboxDeadLetter(
        string $eventId,
        string $actorId,
        string $reason,
    ): DeadLetterRecord {
        return $this->transactions->run(
            function () use (
                $eventId,
                $actorId,
                $reason,
            ): DeadLetterRecord {
                $message = OutboxMessage::query()
                    ->where('event_id', $eventId)
                    ->lockForUpdate()
                    ->first();

                if ($message === null) {
                    throw new RuntimeException(
                        "Outbox dead letter [{$eventId}] was not found.",
                    );
                }

                if ($message->published_at !== null) {
                    throw new InvalidArgumentException(
                        'A published outbox message cannot be replayed.',
                    );
                }

                if ($message->dead_lettered_at === null) {
                    throw new InvalidArgumentException(
                        'The outbox message is not currently dead-lettered.',
                    );
                }

                $failedAt = $this->deadLetteredAt($message);
                $previousAttempts = $message->dispatch_attempts;

                $errorType = $this->errorType(
                    $message->last_error,
                );

                $replayedAt = CarbonImmutable::now();
                $replayCount = $message->replay_count + 1;

                $message->forceFill([
                    'available_at' => $replayedAt,
                    'reserved_until' => null,
                    'reservation_token' => null,
                    'dispatch_attempts' => 0,
                    'last_error' => null,
                    'dead_lettered_at' => null,
                    'replay_count' => $replayCount,
                    'last_replayed_at' => $replayedAt,
                ])->save();

                $this->audit->record(
                    organizationId: $message->organization_id,
                    projectId: $message->project_id,
                    actorType: AuditActorType::System,
                    actorId: $actorId,
                    eventType: AuditEventType::DeadLetterReplayRequested,
                    subjectType: AuditSubjectType::OutboxMessage,
                    subjectId: $message->event_id,
                    correlationId: $message->correlation_id,
                    metadata: [
                        'source' => DeadLetterSource::Outbox->value,
                        'reason' => $this->redactor->message($reason),
                        'previous_dispatch_attempts' => $previousAttempts,
                        'replay_count' => $replayCount,
                        'previous_error_type' => $errorType,
                    ],
                    causationId: $message->event_id,
                    executionId: $message->execution_id,
                );

                return new DeadLetterRecord(
                    source: DeadLetterSource::Outbox,
                    id: $message->event_id,
                    eventId: $message->event_id,
                    eventName: $message->event_name,
                    attempts: $previousAttempts,
                    failedAt: $failedAt,
                    errorType: $errorType,
                );
            },
        );
    }

    /**
     * Rebuild and queue one known-safe consumer job, then remove its Laravel
     * failed-job record.
     *
     * Publication happens before forgetting the failure. A crash between those
     * operations may enqueue a duplicate, but existing consumer deduplication
     * prevents repeated domain side effects. Forgetting first could lose the
     * event permanently.
     */
    private function replayQueueDeadLetter(
        string $failedJobId,
        string $actorId,
        string $reason,
    ): DeadLetterRecord {
        $failedJob = $this->failedJobs->find($failedJobId);

        if (! is_object($failedJob)) {
            throw new RuntimeException(
                "Failed queue job [{$failedJobId}] was not found.",
            );
        }

        $attributes = (array) $failedJob;
        $payload = $this->decodePayload(
            $attributes['payload'] ?? null,
        );
        $job = $this->extractConsumeOutboxMessage($payload);

        $message = OutboxMessage::query()
            ->where('event_id', $job->eventId)
            ->first();

        if ($message === null) {
            throw new InvalidArgumentException(
                'The failed consumer job references a missing outbox event.',
            );
        }

        $failedAt = CarbonImmutable::parse(
            (string) ($attributes['failed_at'] ?? 'now'),
        );

        $errorType = $this->errorType(
            is_string($attributes['exception'] ?? null)
                ? $attributes['exception']
                : null,
        );

        $this->audit->record(
            organizationId: $message->organization_id,
            projectId: $message->project_id,
            actorType: AuditActorType::System,
            actorId: $actorId,
            eventType: AuditEventType::DeadLetterReplayRequested,
            subjectType: AuditSubjectType::FailedQueueJob,
            subjectId: $failedJobId,
            correlationId: $message->correlation_id,
            metadata: [
                'source' => DeadLetterSource::Queue->value,
                'reason' => $this->redactor->message($reason),
                'event_id' => $job->eventId,
                'previous_error_type' => $errorType,
            ],
            causationId: $message->event_id,
            executionId: $message->execution_id,
        );

        $this->transport->publish($job->eventId);

        if (! $this->failedJobs->forget($failedJobId)) {
            throw new RuntimeException(
                'The event was requeued, but the failed-job record could not '
                    .'be removed. Consumer deduplication makes a later duplicate '
                    .'replay safe, but operator review is required.',
            );
        }

        return new DeadLetterRecord(
            source: DeadLetterSource::Queue,
            id: $failedJobId,
            eventId: $job->eventId,
            eventName: $message->event_name,
            attempts: (int) ($payload['attempts'] ?? 0),
            failedAt: $failedAt,
            errorType: $errorType,
        );
    }

    /**
     * Decode one failed queue payload using strict JSON handling.
     *
     * @return array<string, mixed>
     */
    private function decodePayload(mixed $payload): array
    {
        if (! is_string($payload) || trim($payload) === '') {
            throw new InvalidArgumentException(
                'The failed queue payload is missing.',
            );
        }

        try {
            $decoded = json_decode(
                $payload,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'The failed queue payload is not valid JSON.',
                previous: $exception,
            );
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException(
                'The failed queue payload must decode to an object.',
            );
        }

        return $decoded;
    }

    /**
     * Extract only the allowlisted event-consumer job.
     *
     * PHP unserialization is restricted to ConsumeOutboxMessage. Unknown,
     * encrypted, malformed, or unrelated command payloads fail closed.
     *
     * @param  array<string, mixed>  $payload
     */
    private function extractConsumeOutboxMessage(
        array $payload,
    ): ConsumeOutboxMessage {
        if (
            ($payload['displayName'] ?? null)
            !== ConsumeOutboxMessage::class
        ) {
            throw new InvalidArgumentException(
                'Only failed ConsumeOutboxMessage jobs may be manually replayed.',
            );
        }

        $data = $payload['data'] ?? null;

        if (! is_array($data)) {
            throw new InvalidArgumentException(
                'The failed consumer job data is missing.',
            );
        }

        $serializedCommand = $data['command'] ?? null;

        if (
            ! is_string($serializedCommand)
            || ! str_starts_with($serializedCommand, 'O:')
        ) {
            throw new InvalidArgumentException(
                'The failed consumer command is not a supported plain payload.',
            );
        }

        set_error_handler(
            static fn (
                int $_severity,
                string $_message,
            ): bool => true,
        );

        try {
            $job = unserialize(
                $serializedCommand,
                [
                    'allowed_classes' => [
                        ConsumeOutboxMessage::class,
                    ],
                ],
            );
        } finally {
            restore_error_handler();
        }

        if (
            ! $job instanceof ConsumeOutboxMessage
            || trim($job->eventId) === ''
        ) {
            throw new InvalidArgumentException(
                'The failed consumer command could not be safely reconstructed.',
            );
        }

        return $job;
    }

    /**
     * Resolve the cast dead-letter timestamp and fail closed when persisted data
     * does not match the model contract.
     */
    private function deadLetteredAt(
        OutboxMessage $message,
    ): CarbonImmutable {
        $failedAt = $message->getAttribute(
            'dead_lettered_at',
        );

        if ($failedAt === null) {
            throw new InvalidArgumentException(
                sprintf(
                    'Outbox message [%s] is not currently dead-lettered.',
                    $message->event_id,
                ),
            );
        }

        if (! $failedAt instanceof CarbonImmutable) {
            throw new RuntimeException(
                sprintf(
                    'Outbox message [%s] has an invalid dead-letter timestamp.',
                    $message->event_id,
                ),
            );
        }

        return $failedAt;
    }

    /**
     * Return only the exception class or a generic safe failure label.
     */
    private function errorType(?string $error): string
    {
        if (is_string($error)) {
            $structuredError = json_decode($error, true);

            if (
                is_array($structuredError)
                && is_string($structuredError['exception_type'] ?? null)
            ) {
                return $structuredError['exception_type'];
            }
        }

        $normalized = trim((string) $error);

        if ($normalized === '') {
            return 'unknown';
        }

        $firstLine = explode(
            "\n",
            $normalized,
            2,
        )[0];

        if (
            preg_match(
                '/\A([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)(?::|\z)/',
                trim($firstLine),
                $matches,
            ) === 1
        ) {
            return $matches[1];
        }

        return 'recorded_failure';
    }

    /**
     * Validate the stable operator identifier used in the audit event.
     */
    private function normalizeActorId(string $actorId): string
    {
        $normalized = $this->normalizeRequiredValue(
            value: $actorId,
            name: 'operator identifier',
            maximumLength: self::MAX_ACTOR_ID_LENGTH,
        );

        if (
            preg_match(
                '/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,190}\z/',
                $normalized,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'The operator identifier contains unsupported characters.',
            );
        }

        return $normalized;
    }

    /**
     * Normalize one required bounded operator value.
     */
    private function normalizeRequiredValue(
        string $value,
        string $name,
        int $maximumLength,
    ): string {
        $normalized = trim($value);

        if ($normalized === '') {
            throw new InvalidArgumentException(
                "The {$name} is required.",
            );
        }

        if (mb_strlen($normalized) > $maximumLength) {
            throw new InvalidArgumentException(
                "The {$name} may not exceed {$maximumLength} characters.",
            );
        }

        return $normalized;
    }
}
