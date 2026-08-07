<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Codex\Recovery\CodexLivenessManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Evaluates one Codex execution attempt using durable liveness facts.
 */
final class EvaluateCodexLivenessJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $timeout = 45;

    public int $tries = 3;

    public int $uniqueFor = 120;

    /**
     * Create one bounded Codex liveness job.
     */
    public function __construct(
        public int $executionAttemptId,
    ) {}

    /**
     * Prevent overlapping liveness evaluation for the same provider attempt.
     */
    public function uniqueId(): string
    {
        return 'codex-liveness:'
            .$this->executionAttemptId;
    }

    /**
     * Evaluate the attempt using the application recovery service.
     */
    public function handle(
        CodexLivenessManager $liveness,
    ): void {
        $liveness->evaluate(
            $this->executionAttemptId,
        );
    }

    /**
     * Apply short infrastructure backoff to liveness-job failures.
     *
     * Provider execution retries remain owned by ExecutionResilienceManager.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [
            5,
            15,
        ];
    }
}
