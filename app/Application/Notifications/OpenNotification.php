<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Models\NotificationEvent;
use App\Models\NotificationRecipient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Marks a recipient-scoped notification as read and resolves its destination.
 */
final readonly class OpenNotification
{
    /**
     * Inject the trusted internal route resolver.
     */
    public function __construct(
        private ResolveNotificationDeepLink $resolveDeepLink,
    ) {}

    /**
     * Open a notification under a database lock.
     *
     * The notification can only be opened by its assigned recipient within its
     * owning organization. Replaying the command preserves the original read
     * timestamp.
     */
    public function handle(
        int $organizationId,
        int $recipientUserId,
        string $notificationRecipientId,
    ): string {
        $this->assertPositiveIdentifier(
            $organizationId,
            'organization',
        );

        $this->assertPositiveIdentifier(
            $recipientUserId,
            'recipient user',
        );

        if (! Str::isUlid($notificationRecipientId)) {
            throw new InvalidArgumentException(
                'The notification recipient identifier is invalid.',
            );
        }

        return DB::transaction(
            function () use (
                $organizationId,
                $recipientUserId,
                $notificationRecipientId,
            ): string {
                $recipient = NotificationRecipient::query()
                    ->forOrganization($organizationId)
                    ->forRecipient($recipientUserId)
                    ->whereKey($notificationRecipientId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $event = NotificationEvent::query()
                    ->forOrganization($organizationId)
                    ->whereKey($recipient->notification_event_id)
                    ->firstOrFail();

                /*
                 * Resolve the destination before changing read state. A broken
                 * or invalid action therefore cannot leave a half-completed
                 * open operation.
                 */
                $destination = $this->resolveDeepLink->handle($event);

                /*
                 * markAsRead() is idempotent and preserves the first read_at
                 * timestamp when this operation is replayed.
                 */
                $recipient->markAsRead();

                return $destination;
            },
            attempts: 3,
        );
    }

    /**
     * Reject invalid numeric identifiers before persistence access.
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
