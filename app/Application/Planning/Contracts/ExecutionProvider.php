<?php

declare(strict_types=1);

namespace App\Application\Planning\Contracts;

use App\Application\Planning\Data\PlanningExecutionRequest;
use App\Application\Planning\Data\PlanningExecutionResult;

/** Provider-neutral boundary for deterministic planning execution. */
interface ExecutionProvider
{
    public function id(): string;

    public function supports(string $capability): bool;

    public function execute(PlanningExecutionRequest $request): PlanningExecutionResult;
}
