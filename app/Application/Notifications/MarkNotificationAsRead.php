<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Models\NotificationRecipient;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Marks one recipient-scoped notification as read idempotently.
 */
final readonly class MarkNotificationAsRead
{
    /**
     * Store the first read timestamp without allowing cross-user access.
     *
     * The row lock prevents simultaneous requests from assigning competing
     * timestamps. Repeated requests return the existing authoritative state.
     */
    public function handle(
        int $organizationId,
        int $recipientUserId,
        string $notificationRecipientId,
    ): NotificationRecipient {
        $this->assertPositiveIdentifier(
            $organizationId,
            'organization',
        );

        $this->assertPositiveIdentifier(
            $recipientUserId,
            'recipient user',
        );

        if (
            preg_match(
                '/\A[0-9A-HJKMNP-TV-Z]{26}\z/',
                $notificationRecipientId,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'The notification recipient identifier is invalid.',
            );
        }

        return DB::transaction(
            function () use (
                $organizationId,
                $recipientUserId,
                $notificationRecipientId,
            ): NotificationRecipient {
                $recipient = NotificationRecipient::query()
                    ->forOrganization($organizationId)
                    ->forRecipient($recipientUserId)
                    ->whereKey($notificationRecipientId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $recipient->markAsRead();

                return $recipient->refresh();
            },
        );
    }

    /**
     * Reject invalid numeric identifiers before accessing persistence.
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
