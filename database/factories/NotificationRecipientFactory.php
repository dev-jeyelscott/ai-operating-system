<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\OrganizationRole;
use App\Models\NotificationEvent;
use App\Models\NotificationRecipient;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationRecipient>
 */
final class NotificationRecipientFactory extends Factory
{
    /**
     * Define one valid notification recipient assignment.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notification_event_id' => NotificationEvent::factory(),
            'recipient_user_id' => User::factory(),
        ];
    }

    /**
     * Derive organization scope and ensure the generated user is a member.
     *
     * The membership is created before the recipient row is inserted so the
     * PostgreSQL membership-validation trigger accepts the assignment.
     */
    public function configure(): static
    {
        return $this->afterMaking(
            function (
                NotificationRecipient $notificationRecipient,
            ): void {
                /** @var NotificationEvent $notificationEvent */
                $notificationEvent = NotificationEvent::query()
                    ->findOrFail(
                        $notificationRecipient->notification_event_id,
                    );

                $notificationRecipient->forceFill([
                    'organization_id' => $notificationEvent->organization_id,
                ]);

                OrganizationMembership::query()->firstOrCreate(
                    [
                        'organization_id' => $notificationEvent->organization_id,
                        'user_id' => $notificationRecipient->recipient_user_id,
                    ],
                    [
                        'role' => OrganizationRole::Member->value,
                    ],
                );
            },
        );
    }

    /**
     * Bind the assignment to one explicit notification event.
     */
    public function forEvent(
        NotificationEvent $notificationEvent,
    ): static {
        return $this->state(fn (): array => [
            'notification_event_id' => $notificationEvent->id,
            'organization_id' => $notificationEvent->organization_id,
        ]);
    }

    /**
     * Bind the assignment to one explicit authenticated user.
     */
    public function forRecipient(User $recipient): static
    {
        return $this->state(fn (): array => [
            'recipient_user_id' => $recipient->id,
        ]);
    }
}
