<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shared\Commands;

use App\Application\Shared\Commands\CommandResult;
use App\Application\Shared\Commands\CommandResultStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the stable command-result factories and serialization contract.
 */
final class CommandResultTest extends TestCase
{
    /**
     * Successful results expose data without an error payload.
     */
    public function test_success_result_has_a_stable_payload(): void
    {
        $result = CommandResult::succeeded([
            'workflow_instance_id' => 42,
        ]);

        self::assertTrue($result->isSuccessful());
        self::assertFalse($result->isRetryable());
        self::assertSame(CommandResultStatus::Succeeded, $result->status);

        self::assertSame([
            'status' => 'succeeded',
            'data' => [
                'workflow_instance_id' => 42,
            ],
            'error' => null,
        ], $result->toArray());
    }

    /**
     * Validation results expose field errors using the existing API code.
     */
    public function test_validation_result_has_a_stable_payload(): void
    {
        $result = CommandResult::validationFailed([
            'state' => [
                'The selected state is invalid.',
            ],
        ]);

        self::assertSame(
            CommandResultStatus::ValidationFailed,
            $result->status,
        );

        self::assertSame('validation_failed', $result->errorCode);

        self::assertSame([
            'fields' => [
                'state' => [
                    'The selected state is invalid.',
                ],
            ],
        ], $result->details);
    }

    /**
     * Conflict results use the stable current-state error contract.
     */
    public function test_conflict_result_has_a_stable_payload(): void
    {
        $result = CommandResult::conflict(
            message: 'The requested transition is no longer allowed.',
            details: [
                'current_state' => 'completed',
            ],
        );

        self::assertSame(CommandResultStatus::Conflict, $result->status);
        self::assertSame('state_conflict', $result->errorCode);
        self::assertFalse($result->isRetryable());

        self::assertSame([
            'current_state' => 'completed',
        ], $result->details);
    }

    /**
     * Retryable results preserve their retry delay.
     */
    public function test_retryable_result_has_a_stable_payload(): void
    {
        $result = CommandResult::retryableFailure(
            message: 'The workflow store is temporarily unavailable.',
            retryAfterSeconds: 15,
        );

        self::assertSame(
            CommandResultStatus::RetryableFailure,
            $result->status,
        );

        self::assertSame(
            'temporarily_unavailable',
            $result->errorCode,
        );

        self::assertTrue($result->isRetryable());
        self::assertSame(15, $result->retryAfterSeconds);
    }

    /**
     * Retryable failures require a positive retry delay.
     */
    public function test_retryable_result_rejects_an_invalid_delay(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->expectExceptionMessage(
            'The retry delay must be at least one second.',
        );

        CommandResult::retryableFailure(
            retryAfterSeconds: 0,
        );
    }
}
