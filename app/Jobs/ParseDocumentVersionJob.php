<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Audit\Data\AuditContext;
use App\Application\Documents\ParseDocumentVersion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Parses a document version after its malware scan has been approved.
 *
 * Duplicate deliveries are safe because ParseDocumentVersion locks the row
 * and validates the current processing status before committing any result.
 */
final class ParseDocumentVersionJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $timeout = 30;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    /**
     * Store the immutable document-version ID and distributed trace values.
     */
    public function __construct(
        public int $documentVersionId,
        public ?string $correlationId = null,
        public ?string $causationId = null,
        public ?string $executionId = null,
    ) {}

    /**
     * Run the idempotent document-parser application service.
     */
    public function handle(
        ParseDocumentVersion $parser,
    ): void {
        $parser->handle(
            id: $this->documentVersionId,
            auditContext: $this->auditContext(),
        );
    }

    /**
     * Record terminal parsing failure after all attempts are exhausted.
     */
    public function failed(?Throwable $exception): void
    {
        app(ParseDocumentVersion::class)->markFailed(
            id: $this->documentVersionId,
            auditContext: $this->auditContext(),
            exception: $exception,
        );
    }

    /**
     * Rebuild the authoritative worker audit context from the queued payload.
     */
    private function auditContext(): AuditContext
    {
        return AuditContext::system(
            actorId: 'document-parse-worker',
            correlationId: $this->correlationId,
            causationId: $this->causationId,
            executionId: $this->executionId,
        );
    }
}
