<?php

declare(strict_types=1);

namespace App\Application\Events\Contracts;

use App\Application\Events\Data\RealTimeStreamMessage;

/**
 * Publishes sanitized projection messages to connected application clients.
 */
interface RealTimeEventStream
{
    /**
     * Publish one transport-neutral real-time message.
     */
    public function publish(RealTimeStreamMessage $message): void;
}
