<?php

declare(strict_types=1);

use App\Domain\Executions\ExecutionCapability;
use InvalidArgumentException;

it('normalizes canonical and historical execution capabilities', function (
    string $storedCapability,
    ExecutionCapability $expected,
): void {
    expect(
        ExecutionCapability::fromStored($storedCapability),
    )->toBe($expected);
})->with([
    [
        'planning.generate',
        ExecutionCapability::PlanningGenerate,
    ],
    [
        'planning.roadmap',
        ExecutionCapability::PlanningGenerate,
    ],
    [
        'development.execute',
        ExecutionCapability::DevelopmentExecute,
    ],
    [
        'development',
        ExecutionCapability::DevelopmentExecute,
    ],
    [
        'development.simulation',
        ExecutionCapability::DevelopmentExecute,
    ],
    [
        'quality_assurance.review',
        ExecutionCapability::QualityAssuranceReview,
    ],
    [
        'quality_assurance.simulation',
        ExecutionCapability::QualityAssuranceReview,
    ],
]);

it('fails closed for an unknown execution capability', function (): void {
    ExecutionCapability::fromStored(
        'development.unsupported',
    );
})->throws(
    InvalidArgumentException::class,
    'Unsupported execution capability',
);
