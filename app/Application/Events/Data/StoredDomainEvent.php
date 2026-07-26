<?php

declare(strict_types=1);

namespace App\Application\Events\Data;

/**
 * Carries one persisted canonical event envelope to registered consumers.
 */
final readonly class StoredDomainEvent
{
    /**
     * Create immutable consumer input from the authoritative outbox record.
     *
     * @param  array<string, mixed>  $envelope
     */
    public function __construct(
        public string $eventId,
        public string $eventName,
        public int $organizationId,
        public ?int $projectId,
        public int $schemaVersion,
        public array $envelope,
    ) {}

    /**
     * Return the canonical event payload.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = $this->envelope['payload'] ?? [];

        if (! is_array($payload)) {
            return [];
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
