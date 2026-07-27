<?php

declare(strict_types=1);

namespace App\Infrastructure\Events;

use App\Application\Events\Contracts\RealTimeEventStream;
use App\Application\Events\Data\RealTimeStreamMessage;

/**
 * Disables external real-time delivery without changing application consumers.
 */
final readonly class NullRealTimeEventStream implements RealTimeEventStream
{
    /**
     * Intentionally discard one message when streaming is disabled.
     */
    public function publish(RealTimeStreamMessage $message): void
    {
        // Intentionally left blank.
    }
}
