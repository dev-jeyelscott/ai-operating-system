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

it('returns canonical and historical stored values', function (
    ExecutionCapability $capability,
    array $expected,
): void {
    expect($capability->storedValues())->toBe($expected);
})->with([
    [
        ExecutionCapability::PlanningGenerate,
        [
            'planning.generate',
            'planning.roadmap',
        ],
    ],
    [
        ExecutionCapability::DevelopmentExecute,
        [
            'development.execute',
            'development',
            'development.simulation',
        ],
    ],
    [
        ExecutionCapability::QualityAssuranceReview,
        [
            'quality_assurance.review',
            'quality_assurance.simulation',
        ],
    ],
]);

it('accepts canonical and historical capability values', function (): void {
    expect(
        ExecutionCapability::PlanningGenerate
            ->accepts('planning.generate'),
    )->toBeTrue()
        ->and(
            ExecutionCapability::PlanningGenerate
                ->accepts('planning.roadmap'),
        )->toBeTrue()
        ->and(
            ExecutionCapability::DevelopmentExecute
                ->accepts('development.execute'),
        )->toBeTrue()
        ->and(
            ExecutionCapability::DevelopmentExecute
                ->accepts('development.simulation'),
        )->toBeTrue()
        ->and(
            ExecutionCapability::QualityAssuranceReview
                ->accepts('quality_assurance.simulation'),
        )->toBeTrue()
        ->and(
            ExecutionCapability::DevelopmentExecute
                ->accepts('planning.generate'),
        )->toBeFalse();
});

it('fails closed for an unknown execution capability', function (): void {
    ExecutionCapability::fromStored(
        'development.unsupported',
    );
})->throws(
    InvalidArgumentException::class,
    'Unsupported execution capability',
);
