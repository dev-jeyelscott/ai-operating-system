<?php

declare(strict_types=1);

namespace App\Application\Approvals\Handlers;

use App\Application\Approvals\Commands\RequestApproval;
use App\Application\Approvals\RecordApprovalLifecycleEvent;
use App\Application\Shared\Commands\CommandResult;
use App\Application\Shared\Contracts\TransactionManager;
use App\Application\Shared\Exceptions\ConflictException;
use App\Models\Approval;
use App\Models\Execution;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkflowInstance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

/**
 * Creates one replay-safe approval request.
 */
final readonly class RequestApprovalHandler
{
    private const MAX_PAYLOAD_BYTES = 16_384;

    /**
     * Inject transaction and lifecycle-event services.
     */
    public function __construct(
        private TransactionManager $transactions,
        private RecordApprovalLifecycleEvent $events,
    ) {}

    /**
     * Validate, authorize, and persist the approval request atomically.
     */
    public function handle(RequestApproval $command): CommandResult
    {
        $requestKey = $this->normalizeIdentifier(
            value: $command->idempotencyKey,
            name: 'approval request idempotency',
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

        [$payload, $fingerprint] = $this->normalizeRequest(
            command: $command,
        );

        return $this->transactions->run(
            function () use (
                $command,
                $requestKey,
                $correlationId,
                $causationId,
                $payload,
                $fingerprint,
            ): CommandResult {
                $project = Project::query()->findOrFail(
                    $command->projectId,
                );

                $requester = $this->resolveRequester(
                    userId: $command->requestedByUserId,
                    project: $project,
                );

                $this->validateReferences(
                    command: $command,
                    project: $project,
                );

                $existing = Approval::query()
                    ->forProject($project->id)
                    ->where(
                        'request_idempotency_key',
                        $requestKey,
                    )
                    ->first();

                if ($existing !== null) {
                    $this->assertReplayMatches(
                        approval: $existing,
                        fingerprint: $fingerprint,
                    );

                    return $this->successfulResult(
                        approval: $existing,
                        replayed: true,
                    );
                }

                $expiresAt = $command->expiresAt?->utc();
                $now = CarbonImmutable::now();

                if (
                    $expiresAt !== null
                    && $expiresAt->lessThanOrEqualTo($now)
                ) {
                    throw new InvalidArgumentException(
                        'A new approval expiry must be in the future.',
                    );
                }

                /*
                 * createOrFirst makes concurrent creation safe when another
                 * worker wins the unique request-key insert race.
                 */
                $approval = Approval::query()->createOrFirst(
                    [
                        'project_id' => $project->id,
                        'request_idempotency_key' => $requestKey,
                    ],
                    [
                        'workflow_instance_id' => $command->workflowInstanceId,
                        'execution_id' => $command->executionId,
                        'type' => $command->type,
                        'requested_by_user_id' => $requester?->id,
                        'request_fingerprint' => $fingerprint,
                        'request_payload' => $payload,
                        'requested_at' => $now,
                        'expires_at' => $expiresAt,
                    ],
                );

                if (! $approval->wasRecentlyCreated) {
                    $this->assertReplayMatches(
                        approval: $approval,
                        fingerprint: $fingerprint,
                    );

                    return $this->successfulResult(
                        approval: $approval,
                        replayed: true,
                    );
                }

                $approval->load('project');

                $this->events->requested(
                    approval: $approval,
                    requester: $requester,
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
     * Resolve and authorize an optional user-initiated requester.
     */
    private function resolveRequester(
        ?int $userId,
        Project $project,
    ): ?User {
        if ($userId === null) {
            return null;
        }

        if ($userId < 1) {
            throw new InvalidArgumentException(
                'The approval requester identifier must be positive.',
            );
        }

        $requester = User::query()->findOrFail($userId);

        Gate::forUser($requester)->authorize(
            'update',
            $project,
        );

        return $requester;
    }

    /**
     * Ensure workflow and execution references belong to the same project.
     */
    private function validateReferences(
        RequestApproval $command,
        Project $project,
    ): void {
        if (
            $command->workflowInstanceId !== null
            && $command->workflowInstanceId < 1
        ) {
            throw new InvalidArgumentException(
                'The workflow instance identifier must be positive.',
            );
        }

        if ($command->workflowInstanceId !== null) {
            $workflowExists = WorkflowInstance::query()
                ->whereKey($command->workflowInstanceId)
                ->where('project_id', $project->id)
                ->exists();

            if (! $workflowExists) {
                throw new InvalidArgumentException(
                    'The approval workflow reference is invalid.',
                );
            }
        }

        if ($command->executionId === null) {
            return;
        }

        if (! Str::isUlid($command->executionId)) {
            throw new InvalidArgumentException(
                'The approval execution identifier must be a valid ULID.',
            );
        }

        $execution = Execution::query()
            ->whereKey($command->executionId)
            ->where('project_id', $project->id)
            ->first();

        if ($execution === null) {
            throw new InvalidArgumentException(
                'The approval execution reference is invalid.',
            );
        }

        if (
            $command->workflowInstanceId !== null
            && (int) $execution->workflow_instance_id
                !== $command->workflowInstanceId
        ) {
            throw new InvalidArgumentException(
                'The approval workflow and execution references do not match.',
            );
        }
    }

    /**
     * Normalize the request payload and calculate its immutable fingerprint.
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function normalizeRequest(
        RequestApproval $command,
    ): array {
        foreach ($command->payload as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException(
                    'The approval payload must use string keys.',
                );
            }
        }

        /** @var array<string, mixed> $payload */
        $payload = $this->canonicalize($command->payload);

        try {
            $encodedPayload = json_encode(
                $payload,
                JSON_THROW_ON_ERROR,
            );

            if (strlen($encodedPayload) > self::MAX_PAYLOAD_BYTES) {
                throw new InvalidArgumentException(
                    'The approval payload may not exceed 16 KiB.',
                );
            }

            $fingerprintMaterial = json_encode(
                [
                    'type' => $command->type->value,
                    'workflow_instance_id' => $command->workflowInstanceId,
                    'execution_id' => $command->executionId,
                    'requested_by_user_id' => $command->requestedByUserId,
                    'expires_at' => $command->expiresAt?->utc()->toISOString(),
                    'payload' => $payload,
                ],
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'The approval request must be JSON serializable.',
                previous: $exception,
            );
        }

        return [
            $payload,
            hash('sha256', $fingerprintMaterial),
        ];
    }

    /**
     * Canonically sort associative arrays while preserving list ordering.
     */
    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[$key] = $this->canonicalize($item);
        }

        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }

    /**
     * Reject reuse of a request key with different request content.
     */
    private function assertReplayMatches(
        Approval $approval,
        string $fingerprint,
    ): void {
        if (! hash_equals(
            $approval->request_fingerprint,
            $fingerprint,
        )) {
            throw new ConflictException(
                'The approval request idempotency key was already used with different content.',
            );
        }
    }

    /**
     * Build the stable successful command result.
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
