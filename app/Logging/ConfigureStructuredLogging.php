<?php

declare(strict_types=1);

namespace App\Logging;

use App\Support\Security\SensitiveValueRedactor;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Logger;
use Monolog\LogRecord;

final class ConfigureStructuredLogging
{
    /**
     * Create the structured logging configurator.
     */
    public function __construct(
        private readonly SensitiveValueRedactor $redactor,
    ) {}

    /**
     * Configure JSON formatting and redact sensitive structured context
     * before each log record is written.
     */
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(
            fn (LogRecord $record): LogRecord => $record->with(
                context: $this->redactor->redact($record->context),
                extra: $this->redactor->redact($record->extra),
            ),
        );

        $formatter = new JsonFormatter(
            JsonFormatter::BATCH_MODE_JSON,
            true,
            false,
            true,
        );

        foreach ($logger->getHandlers() as $handler) {
            // Not every Monolog handler supports assigning a formatter.
            if (! $handler instanceof FormattableHandlerInterface) {
                continue;
            }

            $handler->setFormatter($formatter);
        }
    }
}
