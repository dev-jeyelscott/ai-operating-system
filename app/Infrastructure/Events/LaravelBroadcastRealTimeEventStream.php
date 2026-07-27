<?php

declare(strict_types=1);

namespace App\Infrastructure\Events;

use App\Application\Events\Contracts\RealTimeEventStream;
use App\Application\Events\Data\RealTimeStreamMessage;
use App\Events\RealTimeStreamMessagePublished;
use InvalidArgumentException;

/**
 * Publishes real-time messages through Laravel's configured broadcaster.
 */
final readonly class LaravelBroadcastRealTimeEventStream implements RealTimeEventStream
{
    /**
     * Create the Laravel broadcasting adapter.
     */
    public function __construct(
        private string $connection,
    ) {
        if (trim($this->connection) === '') {
            throw new InvalidArgumentException(
                'The real-time broadcast connection is required.',
            );
        }
    }

    /**
     * Dispatch synchronously so transport failures remain retryable upstream.
     */
    public function publish(RealTimeStreamMessage $message): void
    {
        RealTimeStreamMessagePublished::dispatch(
            $message,
            $this->connection,
        );
    }
}
