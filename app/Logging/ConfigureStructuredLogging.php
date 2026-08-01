<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Logger;

/**
 * Applies the application's stable JSON log format.
 */
final class ConfigureStructuredLogging
{
    /**
     * Configure JSON formatting on compatible handlers.
     */
    public function __invoke(Logger $logger): void
    {
        $formatter = new JsonFormatter(
            JsonFormatter::BATCH_MODE_JSON,
            true,
            false,
            true,
        );

        foreach ($logger->getHandlers() as $handler) {
            if (! $handler instanceof FormattableHandlerInterface) {
                continue;
            }

            $handler->setFormatter($formatter);
        }
    }
}
