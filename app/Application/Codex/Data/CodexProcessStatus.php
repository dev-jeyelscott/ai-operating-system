<?php

declare(strict_types=1);

namespace App\Application\Codex\Data;

/**
 * Describes transient process state without becoming workflow truth.
 */
final readonly class CodexProcessStatus
{
    /**
     * Create one process status snapshot.
     */
    public function __construct(
        public bool $running,
        public bool $initialized,
        public ?int $processId,
        public ?int $exitCode,
        public int $eventsSeen,
        public int $stderrBytesSeen,
    ) {}
}
