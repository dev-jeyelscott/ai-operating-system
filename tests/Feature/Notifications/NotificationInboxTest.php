<?php

declare(strict_types=1);

use App\Models\NotificationEvent;
use App\Models\NotificationRecipient;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Create one recipient-scoped notification and the required organization
 * membership through the existing factory lifecycle.
 *
 * @return array{
 *     organization: Organization,
 *     user: User,
 *     event: NotificationEvent,
 *     recipient: NotificationRecipient
 * }
 */
function notificationInboxFixture(
    ?Organization $organization = null,
    ?User $user = null,
    array $eventAttributes = [],
    array $recipientAttributes = [],
): array {
    $organization ??= Organization::factory()->create();
    $user ??= User::factory()->create();

    $event = NotificationEvent::factory()
        ->organizationWide($organization)
        ->create($eventAttributes);

    $recipient = NotificationRecipient::factory()
        ->forEvent($event)
        ->forRecipient($user)
        ->create($recipientAttributes);

    return compact(
        'organization',
        'user',
        'event',
        'recipient',
    );
}

it('delivers persistent unread notifications to the assigned recipient', function (): void {
    $fixture = notificationInboxFixture(
        eventAttributes: [
            'title' => 'Roadmap ready for approval',
            'message' => 'The generated roadmap is ready for review.',
        ],
    );

    $response = $this
        ->actingAs($fixture['user'])
        ->get(route(
            'organizations.dashboard',
            $fixture['organization'],
        ));

    $response
        ->assertOk()
        ->assertInertia(
            fn (Assert $page): Assert => $page
                ->where('notifications.unreadCount', 1)
                ->has('notifications.items', 1)
                ->where(
                    'notifications.items.0.id',
                    $fixture['recipient']->id,
                )
                ->where(
                    'notifications.items.0.title',
                    'Roadmap ready for approval',
                )
                ->where(
                    'notifications.items.0.readAt',
                    null,
                ),
        );

    expect(
        $fixture['recipient']->refresh()->delivered_at,
    )->not->toBeNull();
});

it('does not expose another recipients notification', function (): void {
    $organization = Organization::factory()->create();
    $currentUser = User::factory()->create();
    $otherUser = User::factory()->create();

    $currentFixture = notificationInboxFixture(
        organization: $organization,
        user: $currentUser,
        eventAttributes: [
            'title' => 'Current user notification',
        ],
    );

    notificationInboxFixture(
        organization: $organization,
        user: $otherUser,
        eventAttributes: [
            'title' => 'Other user notification',
        ],
    );

    $response = $this
        ->actingAs($currentUser)
        ->get(route(
            'organizations.dashboard',
            $organization,
        ));

    $response
        ->assertOk()
        ->assertInertia(
            fn (Assert $page): Assert => $page
                ->where('notifications.unreadCount', 1)
                ->has('notifications.items', 1)
                ->where(
                    'notifications.items.0.id',
                    $currentFixture['recipient']->id,
                )
                ->where(
                    'notifications.items.0.title',
                    'Current user notification',
                ),
        );
});

it('marks an assigned notification as read', function (): void {
    $fixture = notificationInboxFixture();

    $response = $this
        ->actingAs($fixture['user'])
        ->patch(route(
            'organizations.notifications.read',
            [
                'organization' => $fixture['organization'],
                'notificationRecipient' => $fixture['recipient']->id,
            ],
        ));

    $response->assertRedirect();

    expect(
        $fixture['recipient']->refresh()->read_at,
    )->not->toBeNull();
});

it('preserves the first read timestamp when the command is replayed', function (): void {
    $fixture = notificationInboxFixture();

    $url = route(
        'organizations.notifications.read',
        [
            'organization' => $fixture['organization'],
            'notificationRecipient' => $fixture['recipient']->id,
        ],
    );

    $this
        ->actingAs($fixture['user'])
        ->patch($url)
        ->assertRedirect();

    $firstReadAt = $fixture['recipient']
        ->refresh()
        ->read_at;

    expect($firstReadAt)->not->toBeNull();

    $this->travel(5)->minutes();

    $this
        ->actingAs($fixture['user'])
        ->patch($url)
        ->assertRedirect();

    $secondReadAt = $fixture['recipient']
        ->refresh()
        ->read_at;

    expect(
        $secondReadAt?->equalTo($firstReadAt),
    )->toBeTrue();
});

it('returns not found when reading another users notification', function (): void {
    $organization = Organization::factory()->create();
    $currentUser = User::factory()->create();
    $otherUser = User::factory()->create();

    /*
     * Give the current user organization access without granting access to the
     * other recipient's notification.
     */
    notificationInboxFixture(
        organization: $organization,
        user: $currentUser,
    );

    $otherFixture = notificationInboxFixture(
        organization: $organization,
        user: $otherUser,
    );

    $this
        ->actingAs($currentUser)
        ->patch(route(
            'organizations.notifications.read',
            [
                'organization' => $organization,
                'notificationRecipient' => $otherFixture['recipient']->id,
            ],
        ))
        ->assertNotFound();

    expect(
        $otherFixture['recipient']->refresh()->read_at,
    )->toBeNull();
});
