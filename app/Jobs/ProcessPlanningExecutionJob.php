<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Planning\ProcessPlanningExecution;
use App\Domain\Executions\ExecutionStatus;
use App\Models\Execution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Queued, replay-safe entry point for one durable planning execution. */
final class ProcessPlanningExecutionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $timeout = 900;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public string $executionId,
        public string $scenario = 'happy_path',
        public int $seed = 1,
        public ?string $feedbackFingerprint = null,
    ) {}

    public function uniqueId(): string
    {
        return 'planning-execution:'.$this->executionId;
    }

    public function handle(ProcessPlanningExecution $planning): void
    {
        $execution = Execution::query()->findOrFail($this->executionId);
        if ($execution->status !== ExecutionStatus::Queued) {
            return;
        }

        $planning->handle(
            $execution,
            $this->scenario,
            $this->seed,
            $this->feedbackFingerprint,
        );
    }
}
