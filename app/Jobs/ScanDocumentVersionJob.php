<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Audit\Data\AuditContext;
use App\Application\Documents\ScanDocumentVersion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Runs a malware scan outside the upload request after its transaction commits.
 *
 * Duplicate deliveries are safe because ScanDocumentVersion acquires a row
 * lock and only processes versions currently in the quarantined state.
 */
final class ScanDocumentVersionJob implements ShouldQueue
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
     * Run the idempotent malware-scan application service.
     */
    public function handle(
        ScanDocumentVersion $scanDocumentVersion,
    ): void {
        $scanDocumentVersion->handle(
            documentVersionId: $this->documentVersionId,
            auditContext: $this->auditContext(),
        );
    }

    /**
     * Record terminal scan failure after all queue attempts are exhausted.
     */
    public function failed(?Throwable $exception): void
    {
        app(ScanDocumentVersion::class)->markFailed(
            documentVersionId: $this->documentVersionId,
            auditContext: $this->auditContext(),
        );
    }

    /**
     * Rebuild the authoritative worker audit context from the queued payload.
     */
    private function auditContext(): AuditContext
    {
        return AuditContext::system(
            actorId: 'document-scan-worker',
            correlationId: $this->correlationId,
            causationId: $this->causationId,
            executionId: $this->executionId,
        );
    }
}
