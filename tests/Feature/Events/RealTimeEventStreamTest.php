<?php

declare(strict_types=1);

use App\Application\Events\Contracts\RealTimeEventStream;
use App\Application\Events\Data\RealTimeStreamMessage;
use App\Broadcasting\OrganizationEventStreamChannel;
use App\Broadcasting\ProjectEventStreamChannel;
use App\Events\RealTimeStreamMessagePublished;
use App\Infrastructure\Events\LaravelBroadcastRealTimeEventStream;
use App\Infrastructure\Events\NullRealTimeEventStream;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

it('resolves the configured broadcast adapter', function (): void {
    config([
        'event-stream.default' => 'broadcast',
        'event-stream.broadcast.connection' => 'null',
    ]);

    expect(app(RealTimeEventStream::class))
        ->toBeInstanceOf(LaravelBroadcastRealTimeEventStream::class);
});

it('resolves the null adapter without changing the application contract', function (): void {
    config([
        'event-stream.default' => 'null',
    ]);

    expect(app(RealTimeEventStream::class))
        ->toBeInstanceOf(NullRealTimeEventStream::class);
});

it('fails closed for an unsupported stream driver', function (): void {
    config([
        'event-stream.default' => 'unsupported',
    ]);

    expect(
        fn (): RealTimeEventStream => app(RealTimeEventStream::class),
    )->toThrow(
        LogicException::class,
        'Unsupported real-time event-stream driver [unsupported].',
    );
});

it('publishes the sanitized message through Laravel broadcasting', function (): void {
    config([
        'event-stream.default' => 'broadcast',
        'event-stream.broadcast.connection' => 'null',
    ]);

    Event::fake([
        RealTimeStreamMessagePublished::class,
    ]);

    $message = new RealTimeStreamMessage(
        eventId: (string) Str::ulid(),
        eventName: 'office.projection_updated',
        organizationId: 10,
        projectId: 25,
        occurredAt: CarbonImmutable::now(),
        correlationId: (string) Str::ulid(),
        executionId: 'execution-01',
        schemaVersion: 1,
        data: [
            'agent_state' => 'planning',
        ],
    );

    app(RealTimeEventStream::class)->publish($message);

    Event::assertDispatched(
        RealTimeStreamMessagePublished::class,
        function (
            RealTimeStreamMessagePublished $event,
        ) use ($message): bool {
            $channel = $event->broadcastOn();

            return $event->message === $message
                && $event->broadcastAs() === $message->eventName
                && $event->broadcastWith() === $message->toArray()
                && $event->broadcastConnections() === ['null']
                && $channel instanceof PrivateChannel
                && $channel->name === 'private-'.$message->channelName();
        },
    );
});

it('authorizes only organization members for organization streams', function (): void {
    $organization = Organization::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();

    OrganizationMembership::factory()
        ->for($organization)
        ->for($member)
        ->create();

    $channel = app(OrganizationEventStreamChannel::class);

    expect($channel->join($member, $organization->id))
        ->toBeTrue()
        ->and($channel->join($outsider, $organization->id))
        ->toBeFalse();
});

it('authorizes project viewers only within the matching organization', function (): void {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $project = Project::factory()
        ->for($organization)
        ->create();

    OrganizationMembership::factory()
        ->for($organization)
        ->for($member)
        ->create();

    $channel = app(ProjectEventStreamChannel::class);

    expect(
        $channel->join(
            $member,
            $organization->id,
            $project->id,
        ),
    )->toBeTrue()
        ->and(
            $channel->join(
                $outsider,
                $organization->id,
                $project->id,
            ),
        )->toBeFalse()
        ->and(
            $channel->join(
                $member,
                $otherOrganization->id,
                $project->id,
            ),
        )->toBeFalse();
});
