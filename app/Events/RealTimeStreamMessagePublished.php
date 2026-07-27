<?php

declare(strict_types=1);

namespace App\Events;

use App\Application\Events\Data\RealTimeStreamMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithBroadcasting;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Adapts one transport-neutral stream message to Laravel broadcasting.
 */
final class RealTimeStreamMessagePublished implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithBroadcasting;

    /**
     * Create a synchronous broadcast event on the configured connection.
     */
    public function __construct(
        public readonly RealTimeStreamMessage $message,
        string $connection,
    ) {
        $this->broadcastVia($connection);
    }

    /**
     * Broadcast only to the tenant-scoped private stream channel.
     */
    public function broadcastOn(): Channel
    {
        return new PrivateChannel($this->message->channelName());
    }

    /**
     * Preserve the transport-neutral dotted event name for clients.
     */
    public function broadcastAs(): string
    {
        return $this->message->eventName;
    }

    /**
     * Publish only the explicit sanitized message contract.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->message->toArray();
    }
}
