<?php

declare(strict_types=1);

use App\Application\Operations\ResolveOfficeAgentRoom;

/**
 * Return the state-aware resolver under test.
 */
function officeAgentRoomResolver(): ResolveOfficeAgentRoom
{
    return new ResolveOfficeAgentRoom;
}

it('keeps active agents in their normal layer rooms', function (
    string $layer,
    string $state,
    string $expectedRoom,
): void {
    expect(officeAgentRoomResolver()->handle(
        layer: $layer,
        officeState: $state,
    ))->toBe($expectedRoom);
})->with([
    'planning' => ['planning', 'planning', 'planning_room'],
    'development' => ['development', 'implementing', 'development_floor'],
    'quality assurance' => [
        'quality_assurance',
        'reviewing',
        'qa_laboratory',
    ],
    'operations' => ['operations', 'validating', 'operations_area'],
]);

it('moves human decisions into the approval room', function (
    string $state,
): void {
    expect(officeAgentRoomResolver()->handle(
        layer: 'development',
        officeState: $state,
    ))->toBe('approval_room');
})->with([
    'waiting for approval' => ['waiting_for_approval'],
    'waiting for human' => ['waiting_for_human'],
]);

it('moves recovery states into the operations area', function (
    string $state,
): void {
    expect(officeAgentRoomResolver()->handle(
        layer: 'quality_assurance',
        officeState: $state,
    ))->toBe('operations_area');
})->with([
    'blocked' => ['blocked'],
    'retrying' => ['retrying'],
    'failed' => ['failed'],
]);

it('moves completed agents into completed work', function (): void {
    expect(officeAgentRoomResolver()->handle(
        layer: 'development',
        officeState: 'completed',
    ))->toBe('archive');
});

it('falls back to operations for an unknown layer', function (): void {
    expect(officeAgentRoomResolver()->handle(
        layer: 'future_layer',
        officeState: 'idle',
    ))->toBe('operations_area');
});
