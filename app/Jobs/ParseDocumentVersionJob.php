<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Audit\Data\AuditContext;
use App\Application\Documents\ParseDocumentVersion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

final class ParseDocumentVersionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $timeout = 30;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(
        public int $documentVersionId,
        public ?string $correlationId = null,
        public ?string $causationId = null,
        public ?string $executionId = null,
    ) {}

    public function handle(ParseDocumentVersion $parser): void
    {
        $parser->handle(
            id: $this->documentVersionId,
            auditContext: AuditContext::system(
                actorId: 'document-parse-worker',
                correlationId: $this->correlationId,
                causationId: $this->causationId,
                executionId: $this->executionId,
            ),
        );
    }

    public function failed(?Throwable $exception): void
    {
        app(ParseDocumentVersion::class)->markFailed(
            id: $this->documentVersionId,
            auditContext: AuditContext::system(
                actorId: 'document-parse-worker',
                correlationId: $this->correlationId,
                causationId: $this->causationId,
                executionId: $this->executionId,
            ),
        );
    }

    public function uniqueId(): string
    {
        return (string) $this->documentVersionId;
    }
}
