<?php

declare(strict_types=1);

namespace App\Application\Development\Contracts;

use App\Application\Development\Data\DevelopmentExecutionRequest;
use App\Application\Development\Data\DevelopmentExecutionResult;

interface DevelopmentExecutionProvider
{
    public function id(): string;

    public function supports(string $capability): bool;

    public function execute(DevelopmentExecutionRequest $request): DevelopmentExecutionResult;
}
