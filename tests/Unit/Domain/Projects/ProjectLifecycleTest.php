<?php

declare(strict_types=1);

use App\Domain\Projects\Exceptions\InvalidProjectStatusTransition;
use App\Domain\Projects\ProjectLifecycle;
use App\Domain\Projects\ProjectStatus;

test('the project lifecycle exposes the defined transition graph', function () {
    $lifecycle = new ProjectLifecycle;

    $expected = [
        'draft' => [
            'configuring',
            'cancelled',
        ],
        'configuring' => [
            'documents_pending',
            'blocked',
            'cancelled',
        ],
        'documents_pending' => [
            'configuring',
            'ready_for_planning',
            'blocked',
            'cancelled',
        ],
        'ready_for_planning' => [
            'configuring',
            'planning',
            'blocked',
            'cancelled',
        ],
        'planning' => [
            'awaiting_roadmap_approval',
            'ready_for_development',
            'blocked',
            'cancelled',
        ],
        'awaiting_roadmap_approval' => [
            'planning',
            'documents_pending',
            'ready_for_development',
            'blocked',
            'cancelled',
        ],
        'ready_for_development' => [
            'planning',
            'active',
            'blocked',
            'cancelled',
        ],
        'active' => [
            'paused',
            'blocked',
            'completed',
            'cancelled',
        ],
        'paused' => [
            'active',
            'blocked',
            'cancelled',
        ],
        'blocked' => [
            'configuring',
            'cancelled',
        ],
        'completed' => [],
        'cancelled' => [],
    ];

    foreach (ProjectStatus::cases() as $status) {
        $actual = array_map(
            static fn (ProjectStatus $target): string => $target->value,
            $lifecycle->allowedTransitions($status),
        );

        expect($actual)->toBe($expected[$status->value]);
    }
});

test('the project lifecycle rejects an invalid transition', function () {
    $lifecycle = new ProjectLifecycle;

    expect(
        fn () => $lifecycle->assertCanTransition(
            from: ProjectStatus::Draft,
            to: ProjectStatus::Active,
        ),
    )->toThrow(
        InvalidProjectStatusTransition::class,
        'Project status cannot transition from [draft] to [active].',
    );
});

test('completed and cancelled projects are terminal', function () {
    expect(ProjectStatus::Completed->isTerminal())
        ->toBeTrue()
        ->and(ProjectStatus::Cancelled->isTerminal())
        ->toBeTrue()
        ->and(ProjectStatus::Active->isTerminal())
        ->toBeFalse();
});

test('the project status vocabulary remains stable', function () {
    expect(ProjectStatus::values())->toBe([
        'draft',
        'configuring',
        'documents_pending',
        'ready_for_planning',
        'planning',
        'awaiting_roadmap_approval',
        'ready_for_development',
        'active',
        'paused',
        'blocked',
        'completed',
        'cancelled',
    ]);
});
