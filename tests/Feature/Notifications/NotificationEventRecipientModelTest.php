<?php

declare(strict_types=1);

use App\Models\NotificationEvent;
use App\Models\NotificationRecipient;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

it('stores a notification event with tenant-safe recipient relationships', function (): void {
    $notificationEvent = NotificationEvent::factory()->create();

    $notificationRecipient = NotificationRecipient::factory()
        ->forEvent($notificationEvent)
        ->create();

    $notificationEvent->refresh();
    $notificationRecipient->refresh();

    $data = $notificationEvent->data;

    expect($notificationEvent->organization->id)
        ->toBe($notificationEvent->organization_id)
        ->and($notificationEvent->project?->id)
        ->toBe($notificationEvent->project_id)
        ->and($data)
        ->toHaveCount(2)
        ->and($data['requires_action'])
        ->toBeTrue()
        ->and($data['decision_type'])
        ->toBe('workflow_approval')
        ->and($notificationEvent->occurred_at)
        ->not->toBeNull()
        ->and($notificationRecipient->notificationEvent->is(
            $notificationEvent,
        ))
        ->toBeTrue()
        ->and($notificationRecipient->recipient->id)
        ->toBe($notificationRecipient->recipient_user_id)
        ->and(
            OrganizationMembership::query()
                ->where(
                    'organization_id',
                    $notificationEvent->organization_id,
                )
                ->where(
                    'user_id',
                    $notificationRecipient->recipient_user_id,
                )
                ->exists(),
        )
        ->toBeTrue();
});

it('deduplicates recipients by source event and user', function (): void {
    $notificationRecipient = NotificationRecipient::factory()->create();

    expect(
        fn () => NotificationRecipient::query()->create([
            'notification_event_id' => $notificationRecipient->notification_event_id,
            'organization_id' => $notificationRecipient->organization_id,
            'recipient_user_id' => $notificationRecipient->recipient_user_id,
        ]),
    )->toThrow(QueryException::class);
});

it('deduplicates notification materialization by source domain event', function (): void {
    $sourceEventId = (string) Str::ulid();

    NotificationEvent::factory()->create([
        'source_event_id' => $sourceEventId,
    ]);

    expect(
        fn () => NotificationEvent::factory()->create([
            'source_event_id' => $sourceEventId,
        ]),
    )->toThrow(QueryException::class);
});

it('allows one event to have multiple recipients', function (): void {
    $notificationEvent = NotificationEvent::factory()->create();

    NotificationRecipient::factory()
        ->forEvent($notificationEvent)
        ->create();

    NotificationRecipient::factory()
        ->forEvent($notificationEvent)
        ->create();

    expect(
        $notificationEvent->recipients()
            ->count(),
    )->toBe(2);
});

it('allows one user to receive different notification events', function (): void {
    $firstRecipient = NotificationRecipient::factory()->create();

    $firstEvent = $firstRecipient->notificationEvent;
    $recipient = $firstRecipient->recipient;

    $secondEvent = NotificationEvent::factory()
        ->forProject($firstEvent->project)
        ->create();

    NotificationRecipient::factory()
        ->forEvent($secondEvent)
        ->forRecipient($recipient)
        ->create();

    expect(
        NotificationRecipient::query()
            ->forOrganization($firstEvent->organization_id)
            ->forRecipient($recipient->id)
            ->count(),
    )->toBe(2);
});

it('rejects recipients outside the notification organization', function (): void {
    $notificationEvent = NotificationEvent::factory()->create();
    $outsideUser = User::factory()->create();

    expect(
        fn () => NotificationRecipient::query()->create([
            'notification_event_id' => $notificationEvent->id,
            'organization_id' => $notificationEvent->organization_id,
            'recipient_user_id' => $outsideUser->id,
        ]),
    )->toThrow(QueryException::class);
});

it('rejects notification project and organization mismatches', function (): void {
    $project = Project::factory()->create();
    $otherOrganization = Organization::factory()->create();

    $notificationEvent = NotificationEvent::factory()
        ->forProject($project)
        ->make();

    $notificationEvent->forceFill([
        'organization_id' => $otherOrganization->id,
    ]);

    expect(
        fn () => $notificationEvent->save(),
    )->toThrow(QueryException::class);
});

it('supports explicit organization project and recipient scopes', function (): void {
    $firstRecipient = NotificationRecipient::factory()->create();
    $secondRecipient = NotificationRecipient::factory()->create();

    $firstEvent = $firstRecipient->notificationEvent;

    expect(
        NotificationEvent::query()
            ->forOrganization($firstEvent->organization_id)
            ->forProject($firstEvent->project_id)
            ->forSourceEvent($firstEvent->source_event_id)
            ->pluck('id')
            ->all(),
    )
        ->toBe([$firstEvent->id])
        ->and(
            NotificationRecipient::query()
                ->forOrganization($firstRecipient->organization_id)
                ->forRecipient($firstRecipient->recipient_user_id)
                ->pluck('id')
                ->all(),
        )
        ->toBe([$firstRecipient->id])
        ->and($secondRecipient->id)
        ->not->toBe($firstRecipient->id);
});
