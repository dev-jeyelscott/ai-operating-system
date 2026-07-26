<?php

declare(strict_types=1);

namespace App\Infrastructure\Idempotency;

use App\Application\Shared\Commands\CommandResult;
use App\Application\Shared\Commands\CommandResultStatus;
use App\Application\Shared\Idempotency\Contracts\IdempotencyKeyService;
use App\Application\Shared\Idempotency\Contracts\IdempotentCommand;
use App\Domain\Idempotency\IdempotencyKeyStatus;
use App\Models\IdempotencyKey;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use LogicException;
use Throwable;

/**
 * Provides durable, encrypted command-result replay using PostgreSQL.
 */
final readonly class DatabaseIdempotencyKeyService implements IdempotencyKeyService
{
    /**
     * Create the service with explicit concurrency and retention limits.
     */
    public function __construct(
        private CacheFactory $cache,
        private ?string $cacheStore,
        private int $lockWaitSeconds,
        private int $processingTtlSeconds,
        private int $retentionSeconds,
    ) {
        if ($this->lockWaitSeconds < 1) {
            throw new InvalidArgumentException(
                'Idempotency lock wait seconds must be at least one.',
            );
        }

        if ($this->processingTtlSeconds < 1) {
            throw new InvalidArgumentException(
                'Idempotency processing TTL must be at least one second.',
            );
        }

        if ($this->retentionSeconds < 1) {
            throw new InvalidArgumentException(
                'Idempotency retention must be at least one second.',
            );
        }
    }

    /**
     * Execute a command once or replay its previously completed result.
     *
     * @param  Closure(): CommandResult  $operation
     */
    public function execute(
        IdempotentCommand $command,
        Closure $operation,
    ): CommandResult {
        $scope = $this->validateIdentifier(
            value: $command->idempotencyScope(),
            field: 'scope',
        );

        $rawKey = $this->validateIdentifier(
            value: $command->idempotencyKey(),
            field: 'key',
        );

        $keyHash = hash('sha256', $rawKey);
        $requestFingerprint = $this->fingerprint(
            $command->idempotencyPayload(),
        );

        /*
     * Never place the raw key in Redis, logs, exceptions, or persistence.
     */
        $lockName = 'idempotency:'.hash(
            'sha256',
            $scope.'|'.$keyHash,
        );

        /*
     * The cache repository contract does not expose atomic locking directly.
     * Resolve its underlying store and require the explicit lock contract.
     */
        $lockProvider = $this->cache
            ->store($this->cacheStore)
            ->getStore();

        if (! $lockProvider instanceof LockProvider) {
            throw new LogicException(
                'The configured idempotency cache store does not support atomic locks.',
            );
        }

        try {
            $result = $lockProvider
                ->lock($lockName, $this->processingTtlSeconds)
                ->block(
                    $this->lockWaitSeconds,
                    fn (): CommandResult => $this->executeUnderLock(
                        command: $command,
                        scope: $scope,
                        keyHash: $keyHash,
                        requestFingerprint: $requestFingerprint,
                        operation: $operation,
                    ),
                );
        } catch (LockTimeoutException) {
            return $this->processingResult($command);
        }

        if (! $result instanceof CommandResult) {
            throw new LogicException(
                'The idempotency lock returned an invalid command result.',
            );
        }

        return $result;
    }

    /**
     * Claim, execute, and complete one command while holding the cache lock.
     *
     * @param  Closure(): CommandResult  $operation
     */
    private function executeUnderLock(
        IdempotentCommand $command,
        string $scope,
        string $keyHash,
        string $requestFingerprint,
        Closure $operation,
    ): CommandResult {
        $claim = $this->claim(
            command: $command,
            scope: $scope,
            keyHash: $keyHash,
            requestFingerprint: $requestFingerprint,
        );

        if ($claim['result'] instanceof CommandResult) {
            return $claim['result'];
        }

        $recordId = $claim['record_id'];
        $ownerToken = $claim['owner_token'];

        if ($recordId === null || $ownerToken === null) {
            throw new LogicException(
                'The idempotency claim did not return an owner.',
            );
        }

        try {
            /** @var CommandResult $result */
            $result = $operation();
        } catch (Throwable $exception) {
            /*
             * An unexpected exception does not represent a stable command
             * result. Release the claim so normal retry policy may execute it.
             */
            $this->releaseClaim(
                recordId: $recordId,
                ownerToken: $ownerToken,
            );

            throw $exception;
        }

        if ($result->isRetryable()) {
            /*
             * Retryable results must not become permanent replays. The caller
             * may submit the same key again after the advised delay.
             */
            $this->releaseClaim(
                recordId: $recordId,
                ownerToken: $ownerToken,
            );

            return $result;
        }

        $this->completeClaim(
            recordId: $recordId,
            ownerToken: $ownerToken,
            result: $result,
        );

        return $result;
    }

    /**
     * Atomically create, replay, reject, or reclaim an idempotency record.
     *
     * @return array{
     *     record_id: string|null,
     *     owner_token: string|null,
     *     result: CommandResult|null
     * }
     */
    private function claim(
        IdempotentCommand $command,
        string $scope,
        string $keyHash,
        string $requestFingerprint,
    ): array {
        $commandClass = $command::class;
        $ownerToken = (string) Str::uuid();
        $newRecordId = (string) Str::ulid();
        $now = CarbonImmutable::now();

        return DB::transaction(
            function () use (
                $command,
                $scope,
                $keyHash,
                $requestFingerprint,
                $commandClass,
                $ownerToken,
                $newRecordId,
                $now,
            ): array {
                /*
                 * PostgreSQL translates insertOrIgnore into ON CONFLICT DO
                 * NOTHING. The unique index remains the final race guard.
                 */
                DB::table('idempotency_keys')->insertOrIgnore([
                    'id' => $newRecordId,
                    'scope' => $scope,
                    'key_hash' => $keyHash,
                    'command_class' => $commandClass,
                    'request_fingerprint' => $requestFingerprint,
                    'status' => IdempotencyKeyStatus::Processing->value,
                    'result_status' => null,
                    'result_payload' => null,
                    'lock_owner' => $ownerToken,
                    'lock_expires_at' => $now->addSeconds(
                        $this->processingTtlSeconds,
                    ),
                    'completed_at' => null,
                    'expires_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                /** @var IdempotencyKey $record */
                $record = IdempotencyKey::query()
                    ->where('scope', $scope)
                    ->where('key_hash', $keyHash)
                    ->lockForUpdate()
                    ->firstOrFail();

                $completedIsActive = $record->status
                    === IdempotencyKeyStatus::Completed
                    && (
                        $record->expires_at === null
                        || $record->expires_at->isFuture()
                    );

                $processingIsActive = $record->status
                    === IdempotencyKeyStatus::Processing
                    && $record->lock_expires_at?->isFuture() === true;

                /*
                 * A key may replay only the exact same command and payload.
                 * Reusing it for different input is a client conflict.
                 */
                if (
                    ($completedIsActive || $processingIsActive)
                    && (
                        $record->command_class !== $commandClass
                        || $record->request_fingerprint
                        !== $requestFingerprint
                    )
                ) {
                    return [
                        'record_id' => null,
                        'owner_token' => null,
                        'result' => CommandResult::conflict(
                            message: 'The idempotency key was already used with different command input.',
                            details: [
                                'reason' => 'idempotency_key_reused',
                                'command' => $commandClass,
                            ],
                        ),
                    ];
                }

                if ($completedIsActive) {
                    return [
                        'record_id' => null,
                        'owner_token' => null,
                        'result' => $this->hydrateStoredResult($record),
                    ];
                }

                /*
                 * This can occur when a Redis lock expired while the original
                 * process still owns an active database claim.
                 */
                if (
                    $processingIsActive
                    && $record->lock_owner !== $ownerToken
                ) {
                    return [
                        'record_id' => null,
                        'owner_token' => null,
                        'result' => $this->processingResult($command),
                    ];
                }

                /*
                 * The row was either newly inserted or its processing/result
                 * retention period expired. Claim it for this execution.
                 */
                $record->forceFill([
                    'command_class' => $commandClass,
                    'request_fingerprint' => $requestFingerprint,
                    'status' => IdempotencyKeyStatus::Processing,
                    'result_status' => null,
                    'result_payload' => null,
                    'lock_owner' => $ownerToken,
                    'lock_expires_at' => $now->addSeconds(
                        $this->processingTtlSeconds,
                    ),
                    'completed_at' => null,
                    'expires_at' => null,
                ])->save();

                return [
                    'record_id' => (string) $record->getKey(),
                    'owner_token' => $ownerToken,
                    'result' => null,
                ];
            },
            attempts: 3,
        );
    }

    /**
     * Persist a terminal command result under row-level ownership validation.
     */
    private function completeClaim(
        string $recordId,
        string $ownerToken,
        CommandResult $result,
    ): void {
        DB::transaction(
            function () use ($recordId, $ownerToken, $result): void {
                /** @var IdempotencyKey $record */
                $record = IdempotencyKey::query()
                    ->whereKey($recordId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $record->status !== IdempotencyKeyStatus::Processing
                    || $record->lock_owner !== $ownerToken
                ) {
                    throw new LogicException(
                        'The idempotency claim ownership was lost before completion.',
                    );
                }

                /*
             * CommandResult is immutable and owns a strongly typed status enum.
             * Persist that authoritative value instead of reparsing its payload.
             */
                $payload = $result->toArray();
                $now = CarbonImmutable::now();

                $record->forceFill([
                    'status' => IdempotencyKeyStatus::Completed,
                    'result_status' => $result->status,
                    'result_payload' => $payload,
                    'lock_owner' => null,
                    'lock_expires_at' => null,
                    'completed_at' => $now,
                    'expires_at' => $now->addSeconds(
                        $this->retentionSeconds,
                    ),
                ])->save();
            },
            attempts: 3,
        );
    }

    /**
     * Delete a non-terminal claim only when the caller still owns it.
     */
    private function releaseClaim(
        string $recordId,
        string $ownerToken,
    ): void {
        IdempotencyKey::query()
            ->whereKey($recordId)
            ->where('status', IdempotencyKeyStatus::Processing->value)
            ->where('lock_owner', $ownerToken)
            ->delete();
    }

    /**
     * Reconstruct the exact stable CommandResult from encrypted persistence.
     */
    private function hydrateStoredResult(
        IdempotencyKey $record,
    ): CommandResult {
        $payload = $record->result_payload;

        if (! is_array($payload)) {
            throw new LogicException(
                'A completed idempotency record has no stored result payload.',
            );
        }

        $statusValue = $payload['status'] ?? null;

        if (! is_string($statusValue)) {
            throw new LogicException(
                'A stored idempotency result has no valid status.',
            );
        }

        $status = CommandResultStatus::tryFrom($statusValue);

        if ($status === null) {
            throw new LogicException(
                'A stored idempotency result has an unknown status.',
            );
        }

        if (
            $record->result_status !== null
            && $record->result_status !== $status
        ) {
            throw new LogicException(
                'The stored idempotency result status is inconsistent.',
            );
        }

        $data = is_array($payload['data'] ?? null)
            ? $payload['data']
            : [];

        $error = is_array($payload['error'] ?? null)
            ? $payload['error']
            : [];

        $message = is_string($error['message'] ?? null)
            ? $error['message']
            : null;

        $details = is_array($error['details'] ?? null)
            ? $error['details']
            : [];

        return match ($status->value) {
            'succeeded' => CommandResult::succeeded($data),

            'validation_failed' => CommandResult::validationFailed(
                fieldErrors: is_array($details['fields'] ?? null)
                    ? $details['fields']
                    : [],
                message: $message ?? 'The submitted data is invalid.',
            ),

            'conflict' => CommandResult::conflict(
                message: $message
                    ?? 'The operation conflicts with current state.',
                details: $details,
            ),

            default => throw new LogicException(
                'Retryable command results must not be stored for replay.',
            ),
        };
    }

    /**
     * Return a stable retryable result for an actively processing duplicate.
     */
    private function processingResult(
        IdempotentCommand $command,
    ): CommandResult {
        return CommandResult::retryableFailure(
            message: 'An identical command is already being processed.',
            retryAfterSeconds: max(1, $this->lockWaitSeconds),
            details: [
                'reason' => 'idempotency_command_in_progress',
                'command' => $command::class,
            ],
        );
    }

    /**
     * Validate caller-controlled scope and key identifiers.
     */
    private function validateIdentifier(
        string $value,
        string $field,
    ): string {
        if (trim($value) === '') {
            throw new InvalidArgumentException(
                sprintf('The idempotency %s must not be empty.', $field),
            );
        }

        if (mb_strlen($value) > 255) {
            throw new InvalidArgumentException(
                sprintf(
                    'The idempotency %s must not exceed 255 characters.',
                    $field,
                ),
            );
        }

        return $value;
    }

    /**
     * Produce a deterministic SHA-256 fingerprint from JSON-safe input.
     *
     * @param  array<string, mixed>  $payload
     */
    private function fingerprint(array $payload): string
    {
        try {
            $json = json_encode(
                $this->canonicalize($payload),
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'The idempotency payload must be valid JSON data.',
                0,
                $exception,
            );
        }

        return hash('sha256', $json);
    }

    /**
     * Canonicalize nested payload values before hashing.
     */
    private function canonicalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(
                    fn (mixed $item): mixed => $this->canonicalize($item),
                    $value,
                );
            }

            $normalized = [];

            foreach ($value as $key => $item) {
                if (! is_string($key)) {
                    throw new InvalidArgumentException(
                        'Idempotency payload objects must use string keys.',
                    );
                }

                $normalized[$key] = $this->canonicalize($item);
            }

            ksort($normalized, SORT_STRING);

            return $normalized;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        throw new InvalidArgumentException(
            sprintf(
                'Unsupported idempotency payload value: %s.',
                get_debug_type($value),
            ),
        );
    }
}
