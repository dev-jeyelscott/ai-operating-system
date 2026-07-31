<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\NotificationRecipientFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Assigns one persistent notification event to one organization member.
 *
 * Delivery occurs when the assignment becomes durable. Read state belongs to
 * this recipient row because each user reads a shared event independently.
 *
 * @property string $id
 * @property string $notification_event_id
 * @property int $organization_id
 * @property int $recipient_user_id
 * @property CarbonImmutable $delivered_at
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable $created_at
 * @property-read NotificationEvent $notificationEvent
 * @property-read Organization $organization
 * @property-read User $recipient
 */
#[DateFormat('Y-m-d H:i:s.u')]
#[Fillable([
    'notification_event_id',
    'organization_id',
    'recipient_user_id',
    'delivered_at',
    'read_at',
])]
final class NotificationRecipient extends Model
{
    /** @use HasFactory<NotificationRecipientFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * Recipient assignments store created_at but intentionally have no
     * updated_at. Read state has its own explicit timestamp.
     */
    public $timestamps = false;

    /**
     * Return the sanitized notification event assigned to this recipient.
     *
     * @return BelongsTo<NotificationEvent, $this>
     */
    public function notificationEvent(): BelongsTo
    {
        return $this->belongsTo(NotificationEvent::class);
    }

    /**
     * Return the organization that owns this recipient assignment.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Return the authenticated user receiving this notification.
     *
     * @return BelongsTo<User, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'recipient_user_id',
        );
    }

    /**
     * Mark the notification as read exactly once.
     *
     * Returning false for an already-read row makes replay idempotent and
     * preserves the original read timestamp.
     */
    public function markAsRead(): bool
    {
        if ($this->read_at !== null) {
            return false;
        }

        $this->forceFill([
            'read_at' => CarbonImmutable::now(),
        ]);

        return $this->save();
    }

    /**
     * Determine whether this recipient has read the notification.
     */
    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Scope assignments to one explicit organization.
     *
     * @param  Builder<NotificationRecipient>  $query
     * @return Builder<NotificationRecipient>
     */
    public function scopeForOrganization(
        Builder $query,
        int $organizationId,
    ): Builder {
        return $query->where(
            $query->getModel()->qualifyColumn('organization_id'),
            $organizationId,
        );
    }

    /**
     * Scope assignments to one authenticated recipient.
     *
     * @param  Builder<NotificationRecipient>  $query
     * @return Builder<NotificationRecipient>
     */
    public function scopeForRecipient(
        Builder $query,
        int $recipientUserId,
    ): Builder {
        return $query->where(
            $query->getModel()->qualifyColumn('recipient_user_id'),
            $recipientUserId,
        );
    }

    /**
     * Scope assignments to unread notifications.
     *
     * @param  Builder<NotificationRecipient>  $query
     * @return Builder<NotificationRecipient>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull(
            $query->getModel()->qualifyColumn('read_at'),
        );
    }

    /**
     * Scope assignments to read notifications.
     *
     * @param  Builder<NotificationRecipient>  $query
     * @return Builder<NotificationRecipient>
     */
    public function scopeRead(Builder $query): Builder
    {
        return $query->whereNotNull(
            $query->getModel()->qualifyColumn('read_at'),
        );
    }

    /**
     * Cast persisted identifiers and timestamps into stable PHP types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'recipient_user_id' => 'integer',
            'delivered_at' => 'immutable_datetime',
            'read_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
