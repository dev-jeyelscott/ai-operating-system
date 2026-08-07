<?php

declare(strict_types=1);

namespace App\Infrastructure\Development;

use App\Application\Development\Contracts\DevelopmentExecutionProvider;
use App\Application\Development\Data\DevelopmentExecutionRequest;
use App\Application\Development\Data\DevelopmentExecutionResult;
use App\Application\Development\SyntheticDevelopmentArtifactGenerator;

final readonly class SimulationDevelopmentProvider implements DevelopmentExecutionProvider
{
    public function __construct(
        private SyntheticDevelopmentArtifactGenerator $generator,
    ) {}

    public function id(): string
    {
        return 'simulation';
    }

    public function supports(string $capability): bool
    {
        return $capability === 'development.simulation';
    }

    public function execute(DevelopmentExecutionRequest $request): DevelopmentExecutionResult
    {
        return $this->generator->generate($request);
    }
}
