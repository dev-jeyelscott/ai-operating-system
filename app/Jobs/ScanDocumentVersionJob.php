<?php

declare(strict_types=1);

namespace App\Jobs;

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

    public function __construct(public int $documentVersionId) {}

    public function handle(ScanDocumentVersion $scanDocumentVersion): void
    {
        $scanDocumentVersion->handle($this->documentVersionId);
    }

    public function failed(?Throwable $exception): void
    {
        app(ScanDocumentVersion::class)->markFailed($this->documentVersionId);
    }

    public function uniqueId(): string
    {
        return (string) $this->documentVersionId;
    }
}
