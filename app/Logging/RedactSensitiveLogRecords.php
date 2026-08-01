<?php

declare(strict_types=1);

namespace App\Logging;

use App\Support\Security\SensitiveValueRedactor;
use Monolog\Logger;
use Monolog\LogRecord;

/**
 * Redacts sensitive values before a Monolog handler receives the record.
 */
final readonly class RedactSensitiveLogRecords
{
    /**
     * Inject the application-wide redactor.
     */
    public function __construct(
        private SensitiveValueRedactor $redactor,
    ) {}

    /**
     * Add a processor that sanitizes messages, context, and extra fields.
     */
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(
            fn (LogRecord $record): LogRecord => $record->with(
                message: $this->redactor->message(
                    $record->message,
                ),
                context: $this->redactor->redact(
                    $record->context,
                ),
                extra: $this->redactor->redact(
                    $record->extra,
                ),
            ),
        );
    }
}
