<?php

declare(strict_types=1);

namespace App\Domain\Events;

use InvalidArgumentException;

/**
 * Represents one positive domain-event payload schema version.
 */
final readonly class EventSchemaVersion
{
    /**
     * Validate and store a schema version.
     */
    public function __construct(
        public int $value,
    ) {
        if ($value < 1) {
            throw new InvalidArgumentException(
                'The domain event schema version must be positive.',
            );
        }
    }

    /**
     * Create the initial schema version.
     */
    public static function initial(): self
    {
        return new self(1);
    }

    /**
     * Create a validated schema version from an integer.
     */
    public static function fromInt(int $value): self
    {
        return new self($value);
    }
}
