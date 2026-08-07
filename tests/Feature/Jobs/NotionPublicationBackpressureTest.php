<?php

declare(strict_types=1);

use App\Jobs\PublishRoadmapToNotionJob;
use App\Jobs\RetryFailedNotionPublicationJob;
use Illuminate\Queue\Middleware\RateLimitedWithRedis;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set([
        'integration-resilience.notion.queue.connection' => 'redis',
        'integration-resilience.notion.queue.name' => 'integrations',
        'integration-resilience.notion.queue.overlap_release_seconds' => 15,
        'integration-resilience.notion.queue.overlap_expire_seconds' => 90,
    ]);
});

test('publication jobs are dispatched to the isolated integrations queue', function (): void {
    Queue::fake();

    $connection = (string) config(
        'integration-resilience.notion.queue.connection',
    );

    $queue = (string) config(
        'integration-resilience.notion.queue.name',
    );

    PublishRoadmapToNotionJob::dispatch(
        actorUserId: 10,
        organizationId: 20,
        roadmapId: 30,
        idempotencyKey: 'publish-request',
        correlationId: 'correlation-id',
    )
        ->onConnection($connection)
        ->onQueue($queue);

    RetryFailedNotionPublicationJob::dispatch(
        actorUserId: 10,
        organizationId: 20,
        roadmapId: 30,
        idempotencyKey: 'retry-request',
        taskIds: [100],
        correlationId: 'correlation-id',
    )
        ->onConnection($connection)
        ->onQueue($queue);

    Queue::assertPushedOn(
        'integrations',
        PublishRoadmapToNotionJob::class,
    );

    Queue::assertPushedOn(
        'integrations',
        RetryFailedNotionPublicationJob::class,
    );
});

test('publication and retry jobs use rate and overlap middleware', function (): void {
    $publish = new PublishRoadmapToNotionJob(
        actorUserId: 10,
        organizationId: 20,
        roadmapId: 30,
        idempotencyKey: 'publish-request',
        correlationId: null,
    );

    $retry = new RetryFailedNotionPublicationJob(
        actorUserId: 10,
        organizationId: 20,
        roadmapId: 30,
        idempotencyKey: 'retry-request',
        taskIds: null,
        correlationId: null,
    );

    foreach ([$publish, $retry] as $job) {
        $middleware = $job->middleware();

        expect($middleware)
            ->toHaveCount(2)
            ->and($middleware[0])
            ->toBeInstanceOf(RateLimitedWithRedis::class)
            ->and($middleware[1])
            ->toBeInstanceOf(WithoutOverlapping::class)
            ->and($job->tries)
            ->toBe(10)
            ->and($job->maxExceptions)
            ->toBe(3)
            ->and($job->timeout)
            ->toBe(60)
            ->and($job->backoff())
            ->toBe([
                5,
                30,
                120,
                300,
            ]);
    }
});

test('horizon reserves independent integration worker capacity', function (): void {
    expect(
        config(
            'horizon.defaults.supervisor-default.queue',
        ),
    )->toBe(['default'])
        ->and(
            config(
                'horizon.defaults.supervisor-integrations.queue',
            ),
        )->toBe(['integrations'])
        ->and(
            config(
                'horizon.environments.production'
                .'.supervisor-integrations.maxProcesses',
            ),
        )->toBe(2);
});
