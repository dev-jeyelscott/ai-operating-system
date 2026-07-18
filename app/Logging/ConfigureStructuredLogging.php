<?php

declare(strict_types=1);

namespace App\Logging;

use App\Support\Security\SensitiveValueRedactor;
use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;
use Monolog\Logger;

final class ConfigureStructuredLogging
{
    public function __construct(
        private readonly SensitiveValueRedactor $redactor,
    ) {
    }

    /**
     * Configure JSON formatting and redact sensitive structured context
     * before the record is written.
     */
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(
            fn (LogRecord $record): LogRecord => $record->with(
                context: $this->redactor->redact($record->context),
                extra: $this->redactor->redact($record->extra),
            ),
        );

        foreach ($logger->getHandlers() as $handler) {
            $handler->setFormatter(
                new JsonFormatter(
                    JsonFormatter::BATCH_MODE_JSON,
                    true,
                    false,
                    true,
                ),
            );
        }
    }
}
