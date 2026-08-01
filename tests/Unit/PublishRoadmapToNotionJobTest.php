<?php

declare(strict_types=1);

use App\Jobs\PublishRoadmapToNotionJob;
use App\Jobs\RepublishNotionConflictJob;
use App\Jobs\RetryFailedNotionPublicationJob;

test('its unique identity is stable for a roadmap and idempotency key', function (): void {
    $first = new PublishRoadmapToNotionJob(
        1,
        2,
        3,
        'publish-1',
        'request-1',
    );

    $replay = new PublishRoadmapToNotionJob(
        1,
        2,
        3,
        'publish-1',
        'request-2',
    );

    $differentRequest = new PublishRoadmapToNotionJob(
        1,
        2,
        3,
        'publish-2',
        'request-1',
    );

    expect($first->uniqueId())
        ->toBe($replay->uniqueId())
        ->not->toBe($differentRequest->uniqueId())
        ->and($first->tries)
        ->toBe(10)
        ->and($first->maxExceptions)
        ->toBe(3)
        ->and($first->timeout)
        ->toBe(60)
        ->and($first->backoff())
        ->toBe([
            5,
            30,
            120,
            300,
        ]);
});

test('the conflict republish job is unique per durable conflict', function (): void {
    $first = new RepublishNotionConflictJob(
        4,
        1,
        2,
        'request-1',
    );

    $retry = new RepublishNotionConflictJob(
        4,
        9,
        2,
        'request-2',
    );

    $otherConflict = new RepublishNotionConflictJob(
        5,
        1,
        2,
        'request-1',
    );

    expect($first->uniqueId())
        ->toBe($retry->uniqueId())
        ->not->toBe($otherConflict->uniqueId())
        ->and($first->tries)
        ->toBe(3)
        ->and($first->backoff)
        ->toBe([
            5,
            30,
            120,
        ]);
});

test('the retry job is unique for one roadmap retry request', function (): void {
    $first = new RetryFailedNotionPublicationJob(
        1,
        2,
        3,
        'retry-1',
        [4],
        'request-1',
    );

    $replay = new RetryFailedNotionPublicationJob(
        9,
        2,
        3,
        'retry-1',
        null,
        'request-2',
    );

    $differentRequest = new RetryFailedNotionPublicationJob(
        1,
        2,
        3,
        'retry-2',
        [4],
        'request-1',
    );

    expect($first->uniqueId())
        ->toBe($replay->uniqueId())
        ->not->toBe($differentRequest->uniqueId())
        ->and($first->tries)
        ->toBe(10)
        ->and($first->maxExceptions)
        ->toBe(3)
        ->and($first->timeout)
        ->toBe(60)
        ->and($first->backoff())
        ->toBe([
            5,
            30,
            120,
            300,
        ]);
});
