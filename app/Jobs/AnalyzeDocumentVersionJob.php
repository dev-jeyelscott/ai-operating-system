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

    /**
     * Maximum execution time for one analysis attempt.
     */
    public int $timeout = 30;

    /**
     * Maximum number of queue attempts before terminal failure.
     */
    public int $tries = 3;

    /**
     * Delays applied between retry attempts.
     *
     * @var list<int>
     */
    public array $backoff = [5, 30, 120];

    /**
     * Store only immutable identifiers and distributed trace information.
     */
    public function __construct(
        public int $documentVersionId,
        public ?string $correlationId = null,
        public ?string $causationId = null,
        public ?string $executionId = null,
    ) {}

    /**
     * Resolve the stable deterministic seed and run document analysis.
     */
    public function handle(
        AnalyzeDocumentVersion $analyzeDocumentVersion,
    ): void {
        $documentVersion = DocumentVersion::query()
            ->findOrFail($this->documentVersionId);

        /*
         * Reuse an already persisted seed during queue retries and explicit
         * processing retries. Otherwise derive it from immutable file identity.
         */
        $seed = $documentVersion->analysis_seed
            ?? $this->seedFromChecksum(
                $documentVersion->checksum_sha256,
            );

        $analyzeDocumentVersion->handle(
            id: $this->documentVersionId,
            seed: $seed,
            auditContext: $this->auditContext(),
        );
    }

    /**
     * Record terminal analysis failure after all attempts are exhausted.
     *
     * The exception message is intentionally not persisted because provider
     * failures may contain document content or other sensitive information.
     */
    public function failed(?Throwable $exception): void
    {
        app(AnalyzeDocumentVersion::class)->markFailed(
            id: $this->documentVersionId,
            auditContext: $this->auditContext(),
        );
    }

    /**
     * Prevent multiple queued analysis jobs for the same document version.
     */
    public function uniqueId(): string
    {
        return "document-analysis:{$this->documentVersionId}";
    }

    /**
     * Build the worker audit context propagated by the previous lifecycle event.
     */
    private function auditContext(): AuditContext
    {
        return AuditContext::system(
            actorId: 'document-analysis-worker',
            correlationId: $this->correlationId,
            causationId: $this->causationId,
            executionId: $this->executionId,
        );
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
