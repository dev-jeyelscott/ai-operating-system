<?php

declare(strict_types=1);

namespace App\Domain\Events;

/**
 * Defines the versioned business payload wrapped by the shared event envelope.
 */
interface DomainEvent
{
    /**
     * Return the stable dotted event name, such as workflow.transition_committed.
     */
    public static function eventName(): string;

    /**
     * Return the positive schema version for this exact payload contract.
     */
    public static function schemaVersion(): int;

    /**
     * Return the JSON-safe business payload for this event version.
     *
     * @return array<string, mixed>
     */
    public function payload(): array;
}
