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
 * Assigns one notification event to one authenticated organization member.
 *
 * Delivery state and read state are intentionally deferred to AIOS-115.
 *
 * @property string $id
 * @property string $notification_event_id
 * @property int $organization_id
 * @property int $recipient_user_id
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
])]
final class NotificationRecipient extends Model
{
    /** @use HasFactory<NotificationRecipientFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * Recipient assignments store created_at but have no updated_at yet.
     */
    public $timestamps = false;

    /**
     * Return the notification event assigned to this recipient.
     *
     * @return BelongsTo<NotificationEvent, $this>
     */
    public function notificationEvent(): BelongsTo
    {
        return $this->belongsTo(NotificationEvent::class);
    }

    /**
     * Return the organization that scopes this recipient assignment.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Return the authenticated user receiving the notification.
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
     * Scope recipient assignments to one explicit organization.
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
     * Scope recipient assignments to one authenticated user.
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
     * Cast persisted identifiers and timestamps safely.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'recipient_user_id' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
