<?php

declare(strict_types=1);

namespace App\Application\Codex\Data;

use Carbon\CarbonImmutable;

/**
 * Carries one ordered, redacted provider event across the process boundary.
 *
 * This object is not itself durable persistence. AIOS-245 will persist its
 * normalized representation.
 */
final readonly class CodexGatewayEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $providerSessionId,
        public string $executionId,
        public int $executionAttemptId,
        public int $sequence,
        public string $method,
        public int|string|null $requestId,
        public ?string $threadId,
        public ?string $turnId,
        public ?string $itemId,
        public ?string $providerCursor,
        public array $payload,
        public string $fingerprintSha256,
        public CarbonImmutable $occurredAt,
    ) {}
}
