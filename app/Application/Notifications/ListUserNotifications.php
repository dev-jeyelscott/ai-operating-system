<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Models\NotificationEvent;
use App\Models\NotificationRecipient;
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
     *         actionUrl: string|null,
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
                        'action_url',
                        'occurred_at',
                    ]);
                },
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        /*
         * Appending with [] guarantees sequential zero-based integer keys.
         * PHPStan can therefore prove that this value is a list rather than
         * only an array with integer keys.
         *
         * @var list<array{
         *     id: string,
         *     eventId: string,
         *     eventName: string,
         *     projectId: int|null,
         *     title: string,
         *     message: string,
         *     actionUrl: string|null,
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
                 * AIOS-115 exposes the persisted URL but does not implement
                 * actionable deep-link behavior. AIOS-116 owns that workflow.
                 */
                'actionUrl' => $event->action_url,

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
