<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Audit\Data\AuditContext;
use App\Application\Documents\AnalyzeDocumentVersion;
use App\Models\DocumentVersion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Runs deterministic document analysis after parsing commits successfully.
 */
final class AnalyzeDocumentVersionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $timeout = 30;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    /**
     * Store only the immutable document-version identifier in the queue payload.
     */
    public function __construct(
        public int $documentVersionId,
        public ?string $correlationId = null,
        public ?string $causationId = null,
        public ?string $executionId = null,
    ) {}

    /**
     * Resolve the stable seed and execute deterministic analysis.
     */
    public function handle(
        AnalyzeDocumentVersion $analyzeDocumentVersion,
    ): void {
        $documentVersion = DocumentVersion::query()
            ->findOrFail($this->documentVersionId);

        $seed = $documentVersion->analysis_seed
            ?? $this->seedFromChecksum(
                $documentVersion->checksum_sha256,
            );

        $analyzeDocumentVersion->handle(
            id: $documentVersion->id,
            seed: $seed,
            auditContext: AuditContext::system(
                actorId: 'document-analysis-worker',
                correlationId: $this->correlationId,
                causationId: $this->causationId,
                executionId: $this->executionId,
            ),
        );
    }

    /**
     * Record terminal analysis failure after all queue attempts are exhausted.
     */
    public function failed(?Throwable $exception): void
    {
        app(AnalyzeDocumentVersion::class)->markFailed(
            id: $this->documentVersionId,
            auditContext: AuditContext::system(
                actorId: 'document-analysis-worker',
                correlationId: $this->correlationId,
                causationId: $this->causationId,
                executionId: $this->executionId,
            ),
        );
    }

    /**
     * Prevent more than one queued analysis job for the same version.
     */
    public function uniqueId(): string
    {
        return "document-analysis:{$this->documentVersionId}";
    }

    /**
     * Derive a repeatable non-negative seed from immutable file identity.
     */
    private function seedFromChecksum(string $checksum): int
    {
        return (int) (
            hexdec(substr($checksum, 0, 8))
            % 2_147_483_647
        );
    }
}
