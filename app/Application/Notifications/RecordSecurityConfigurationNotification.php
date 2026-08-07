<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Audit\Data\AuditEventData;
use App\Models\NotificationEvent;
use App\Models\NotificationRecipient;
use App\Models\OrganizationMembership;
use Illuminate\Support\Facades\DB;

/**
 * Persists a sanitized in-app notification for a security configuration event.
 *
 * Callers supply only allowlisted metadata. Provider secrets, ciphertext,
 * request bodies, authorization headers, and provider response bodies must never
 * be passed to this service.
 */
final readonly class RecordSecurityConfigurationNotification
{
    /**
     * Create one idempotent notification assigned to the authorized actor.
     *
     * @param  array<string, scalar|null>  $data
     */
    public function record(
        AuditEventData $source,
        int $recipientUserId,
        string $title,
        string $message,
        ?string $actionUrl,
        array $data = [],
    ): void {
        if (! OrganizationMembership::query()
            ->where('organization_id', $source->organizationId)
            ->where('user_id', $recipientUserId)
            ->exists()) {
            return;
        }

        DB::transaction(function () use (
            $source,
            $recipientUserId,
            $title,
            $message,
            $actionUrl,
            $data,
        ): void {
            $event = NotificationEvent::query()
                ->where('source_event_id', $source->eventId)
                ->lockForUpdate()
                ->first();

            if ($event === null) {
                $event = new NotificationEvent;
                $event->forceFill([
                    'organization_id' => $source->organizationId,
                    'project_id' => $source->projectId,
                    'source_event_id' => $source->eventId,
                    'event_name' => $source->eventType->value,
                    'title' => mb_substr($title, 0, 255),
                    'message' => mb_substr($message, 0, 2000),
                    'action_url' => $actionUrl,
                    'data' => $data,
                    'correlation_id' => $source->correlationId
                        ?? $source->eventId,
                    'execution_id' => $source->executionId,
                    'occurred_at' => $source->occurredAt,
                    'created_at' => now(),
                ])->save();
            }

            $recipientExists = NotificationRecipient::query()
                ->where('notification_event_id', $event->id)
                ->where('recipient_user_id', $recipientUserId)
                ->exists();

            if ($recipientExists) {
                return;
            }

            $recipient = new NotificationRecipient;
            $recipient->forceFill([
                'notification_event_id' => $event->id,
                'organization_id' => $source->organizationId,
                'recipient_user_id' => $recipientUserId,
                'delivered_at' => now(),
                'read_at' => null,
                'created_at' => now(),
            ])->save();
        });
    }
}
