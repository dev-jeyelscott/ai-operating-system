<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Models\NotificationRecipient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

        /*
         * Use Laravel's native ULID validator instead of a case-sensitive
         * regular expression. Eloquent's HasUlids trait generates canonical
         * lowercase identifiers, which are valid ULIDs.
         */
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
            ): NotificationRecipient {
                /*
                 * Scope the lookup to both the organization and authenticated
                 * recipient so another user's notification remains concealed.
                 */
                $recipient = NotificationRecipient::query()
                    ->forOrganization($organizationId)
                    ->forRecipient($recipientUserId)
                    ->whereKey($notificationRecipientId)
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                 * NotificationRecipient::markAsRead() preserves the original
                 * read_at timestamp when the command is replayed.
                 */
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
