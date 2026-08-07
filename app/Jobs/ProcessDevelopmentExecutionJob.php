<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Development\ProcessDevelopmentExecution;
use App\Domain\Executions\ExecutionStatus;
use App\Models\Execution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

final class ProcessDevelopmentExecutionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public string $executionId, public string $scenario = 'happy_path', public int $seed = 1) {}

    public function uniqueId(): string
    {
        return 'development-execution:'.$this->executionId;
    }

    public function handle(ProcessDevelopmentExecution $development): void
    {
        $execution = Execution::query()->findOrFail($this->executionId);
        if ($execution->status !== ExecutionStatus::Queued) {
            return;
        }

        $development->handle($execution, $this->scenario, $this->seed);
    }
}
