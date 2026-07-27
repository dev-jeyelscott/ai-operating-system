<?php

declare(strict_types=1);

namespace App\Application\Shared\Commands;

use InvalidArgumentException;

/**
 * Immutable and serializable outcome returned by every dispatched command.
 */
final readonly class CommandResult
{
    /**
     * Create one command result.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $details
     */
    private function __construct(
        public CommandResultStatus $status,
        public array $data,
        public ?string $errorCode,
        public ?string $message,
        public array $details,
        public ?int $retryAfterSeconds,
    ) {}

    /**
     * Build a successful command result.
     *
     * @param  array<string, mixed>  $data
     */
    public static function succeeded(array $data = []): self
    {
        return new self(
            status: CommandResultStatus::Succeeded,
            data: $data,
            errorCode: null,
            message: null,
            details: [],
            retryAfterSeconds: null,
        );
    }

    /**
     * Build a validation-failure command result.
     *
     * @param  array<string, array<int, string>>  $fieldErrors
     */
    public static function validationFailed(
        array $fieldErrors = [],
        string $message = 'The submitted data is invalid.',
    ): self {
        return new self(
            status: CommandResultStatus::ValidationFailed,
            data: [],
            errorCode: 'validation_failed',
            message: $message,
            details: [
                'fields' => $fieldErrors,
            ],
            retryAfterSeconds: null,
        );
    }

    /**
     * Build a command result for a valid operation that conflicts with the
     * current authoritative application state.
     *
     * @param  array<string, mixed>  $details
     */
    public static function conflict(
        string $message = 'The operation conflicts with current state.',
        array $details = [],
    ): self {
        return new self(
            status: CommandResultStatus::Conflict,
            data: [],
            errorCode: 'state_conflict',
            message: $message,
            details: $details,
            retryAfterSeconds: null,
        );
    }

    /**
     * Build a transient failure that callers may retry after a bounded delay.
     *
     * @param  array<string, mixed>  $details
     */
    public static function retryableFailure(
        string $message = 'The operation is temporarily unavailable.',
        int $retryAfterSeconds = 30,
        array $details = [],
    ): self {
        if ($retryAfterSeconds < 1) {
            throw new InvalidArgumentException(
                'The retry delay must be at least one second.',
            );
        }

        return new self(
            status: CommandResultStatus::RetryableFailure,
            data: [],
            errorCode: 'temporarily_unavailable',
            message: $message,
            details: $details,
            retryAfterSeconds: $retryAfterSeconds,
        );
    }

    /**
     * Determine whether the command completed successfully.
     */
    public function isSuccessful(): bool
    {
        return $this->status === CommandResultStatus::Succeeded;
    }

    /**
     * Determine whether the caller may safely retry the command.
     */
    public function isRetryable(): bool
    {
        return $this->status === CommandResultStatus::RetryableFailure;
    }

    /**
     * Convert the result into a stable transport-safe payload.
     *
     * @return array{
     *     status: string,
     *     data: array<string, mixed>,
     *     error: null|array{
     *         code: string,
     *         message: string,
     *         retryable: bool,
     *         details: array<string, mixed>,
     *         retry_after_seconds: int|null
     *     }
     * }
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'data' => $this->data,
            'error' => $this->errorCode === null
                ? null
                : [
                    'code' => $this->errorCode,
                    'message' => $this->message ?? '',
                    'retryable' => $this->isRetryable(),
                    'details' => $this->details,
                    'retry_after_seconds' => $this->retryAfterSeconds,
                ],
        ];
    }
}
