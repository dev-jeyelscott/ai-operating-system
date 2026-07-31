<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Models\NotificationEvent;
use App\Models\NotificationRecipient;
use App\Models\Organization;
use InvalidArgumentException;

/**
 * Builds the authenticated user's tenant-scoped in-app notification inbox.
 */
final readonly class ListUserNotifications
{
    /**
     * Return a bounded notification list and authoritative unread count.
     *
     * @return array{
     *     unreadCount: non-negative-int,
     *     items: list<array{
     *         id: string,
     *         eventId: string,
     *         eventName: string,
     *         projectId: int|null,
     *         title: string,
     *         message: string,
     *         actionUrl: string,
     *         deliveredAt: string,
     *         readAt: string|null,
     *         occurredAt: string
     *     }>
     * }
     */
    public function handle(
        int $organizationId,
        int $recipientUserId,
        int $limit = 10,
    ): array {
        $this->assertPositiveIdentifier(
            $organizationId,
            'organization',
        );

        $this->assertPositiveIdentifier(
            $recipientUserId,
            'recipient user',
        );

        if ($limit < 1 || $limit > 50) {
            throw new InvalidArgumentException(
                'The notification inbox limit must be between 1 and 50.',
            );
        }

        $organizationRouteKey = Organization::query()
            ->whereKey($organizationId)
            ->value('slug');

        if (
            ! is_string($organizationRouteKey)
            || $organizationRouteKey === ''
        ) {
            throw new InvalidArgumentException(
                'The notification organization could not be resolved.',
            );
        }

        $recipientQuery = NotificationRecipient::query()
            ->forOrganization($organizationId)
            ->forRecipient($recipientUserId);

        $unreadCount = (clone $recipientQuery)
            ->unread()
            ->count();

        $recipients = (clone $recipientQuery)
            ->with([
                'notificationEvent' => static function ($query): void {
                    $query->select([
                        'id',
                        'project_id',
                        'event_name',
                        'title',
                        'message',
                        'occurred_at',
                    ]);
                },
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        /*
         * Appending with [] guarantees sequential list keys for PHPStan.
         *
         * @var list<array{
         *     id: string,
         *     eventId: string,
         *     eventName: string,
         *     projectId: int|null,
         *     title: string,
         *     message: string,
         *     actionUrl: string,
         *     deliveredAt: string,
         *     readAt: string|null,
         *     occurredAt: string
         * }> $items
         */
        $items = [];

        foreach ($recipients as $recipient) {
            /** @var NotificationEvent $event */
            $event = $recipient->notificationEvent;

            $items[] = [
                'id' => $recipient->id,
                'eventId' => $event->id,
                'eventName' => $event->event_name,
                'projectId' => $event->project_id,
                'title' => $event->title,
                'message' => $event->message,

                /*
                 * The client receives only the server-owned open command URL.
                 * It never receives or follows raw destination metadata.
                 */
                'actionUrl' => route(
                    'organizations.notifications.open',
                    [
                        'organization' => $organizationRouteKey,
                        'notificationRecipient' => $recipient->id,
                    ],
                    false,
                ),

                'deliveredAt' => $recipient
                    ->delivered_at
                    ->toIso8601String(),

                'readAt' => $recipient
                    ->read_at
                    ?->toIso8601String(),

                'occurredAt' => $event
                    ->occurred_at
                    ->toIso8601String(),
            ];
        }

        return [
            'unreadCount' => $unreadCount,
            'items' => $items,
        ];
    }

    /**
     * Reject invalid identifiers before any persistence query executes.
     */
    private function assertPositiveIdentifier(
        int $identifier,
        string $name,
    ): void {
        if ($identifier < 1) {
            throw new InvalidArgumentException(
                "The {$name} identifier must be positive.",
            );
        }
    }
}
