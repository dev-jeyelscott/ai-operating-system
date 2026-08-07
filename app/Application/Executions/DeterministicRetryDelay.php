<?php

declare(strict_types=1);

namespace App\Application\Executions;

use InvalidArgumentException;

/**
 * Calculates bounded exponential backoff with stable hash-based jitter.
 */
final class DeterministicRetryDelay
{
    /**
     * Calculate retry delay for the next attempt.
     */
    public function seconds(
        string $executionId,
        int $nextAttemptNumber,
        int $baseDelaySeconds,
        int $maxDelaySeconds,
        int $jitterPercent,
    ): int {
        $this->assertValidPolicy(
            nextAttemptNumber: $nextAttemptNumber,
            baseDelaySeconds: $baseDelaySeconds,
            maxDelaySeconds: $maxDelaySeconds,
            jitterPercent: $jitterPercent,
        );

        /*
         * Attempt two is the first retry and therefore uses the base delay.
         * Each later retry doubles the delay until the configured maximum.
         */
        $delay = $baseDelaySeconds;

        for (
            $attempt = 3;
            $attempt <= $nextAttemptNumber;
            $attempt++
        ) {
            $delay = min(
                $maxDelaySeconds,
                $delay * 2,
            );

            if ($delay === $maxDelaySeconds) {
                break;
            }
        }

        $jitterRange = intdiv(
            $delay * $jitterPercent,
            100,
        );

        if ($jitterRange === 0) {
            return $delay;
        }

        /*
         * Derive jitter from stable execution input instead of runtime
         * randomness. Replaying the same decision therefore gives the same
         * delay and does not produce different workflow history.
         */
        $hashPrefix = substr(
            hash(
                'sha256',
                sprintf(
                    '%s:%d',
                    $executionId,
                    $nextAttemptNumber,
                ),
            ),
            0,
            8,
        );

        $bucketCount = ($jitterRange * 2) + 1;
        $bucket = (int) (hexdec($hashPrefix) % $bucketCount);
        $offset = $bucket - $jitterRange;

        return max(
            1,
            min(
                $maxDelaySeconds,
                $delay + $offset,
            ),
        );
    }

    /**
     * Reject invalid retry-policy values before performing a calculation.
     */
    private function assertValidPolicy(
        int $nextAttemptNumber,
        int $baseDelaySeconds,
        int $maxDelaySeconds,
        int $jitterPercent,
    ): void {
        if ($nextAttemptNumber < 2) {
            throw new InvalidArgumentException(
                'The next retry attempt number must be at least two.',
            );
        }

        if ($baseDelaySeconds < 1) {
            throw new InvalidArgumentException(
                'The retry base delay must be at least one second.',
            );
        }

        if ($maxDelaySeconds < $baseDelaySeconds) {
            throw new InvalidArgumentException(
                'The retry maximum delay cannot be below the base delay.',
            );
        }

        if ($jitterPercent < 0 || $jitterPercent > 100) {
            throw new InvalidArgumentException(
                'The retry jitter percentage must be between zero and one hundred.',
            );
        }
    }
}
