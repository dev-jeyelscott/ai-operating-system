<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Audit\Data\AuditContext;
use App\Application\Documents\ScanDocumentVersion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Runs a malware scan outside the upload request after its transaction commits.
 */
final class ScanDocumentVersionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $timeout = 30;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    /**
     * Store only scalar identifiers in the durable queue payload.
     */
    public function __construct(
        public int $documentVersionId,
        public ?string $correlationId = null,
        public ?string $causationId = null,
        public ?string $executionId = null,
    ) {}

    /**
     * Execute the scan with the original request trace.
     */
    public function handle(
        ScanDocumentVersion $scanDocumentVersion,
    ): void {
        $scanDocumentVersion->handle(
            documentVersionId: $this->documentVersionId,
            auditContext: AuditContext::system(
                actorId: 'document-scan-worker',
                correlationId: $this->correlationId,
                causationId: $this->causationId,
                executionId: $this->executionId,
            ),
        );
    }

    /**
     * Record terminal failure after Laravel exhausts all attempts.
     */
    public function failed(?Throwable $exception): void
    {
        app(ScanDocumentVersion::class)->markFailed(
            documentVersionId: $this->documentVersionId,
            auditContext: AuditContext::system(
                actorId: 'document-scan-worker',
                correlationId: $this->correlationId,
                causationId: $this->causationId,
                executionId: $this->executionId,
            ),
        );
    }
}
