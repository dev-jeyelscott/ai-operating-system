<?php

declare(strict_types=1);

namespace App\Application\Development\Contracts;

use App\Application\Development\Data\DevelopmentExecutionRequest;
use App\Application\Development\Data\DevelopmentExecutionResult;
use App\Application\Executions\Contracts\DescribesExecutionProvider;

/**
 * Defines the provider-neutral Layer 2 development boundary.
 */
interface DevelopmentExecutionProvider extends DescribesExecutionProvider
{
    /**
     * Execute one validated development request.
     */
    public function execute(
        DevelopmentExecutionRequest $request,
    ): DevelopmentExecutionResult;
}
