<?php

declare(strict_types=1);

namespace App\Domain\Events;

/**
 * Identifies one supported durable dead-letter source.
 */
enum DeadLetterSource: string
{
    case Outbox = 'outbox';
    case Queue = 'queue';

    /**
     * Return values suitable for validation and operator documentation.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $source): string => $source->value,
            self::cases(),
        );
    }
}
