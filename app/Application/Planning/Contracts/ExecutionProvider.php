<?php

declare(strict_types=1);

namespace App\Application\Planning\Contracts;

use App\Application\Executions\Contracts\DescribesExecutionProvider;
use App\Application\Planning\Data\PlanningExecutionRequest;
use App\Application\Planning\Data\PlanningExecutionResult;

/**
 * Defines the provider-neutral Layer 1 planning boundary.
 */
interface ExecutionProvider extends DescribesExecutionProvider
{
    /**
     * Execute one validated planning request.
     */
    public function execute(
        PlanningExecutionRequest $request,
    ): PlanningExecutionResult;
}
