<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Artifact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Removes expired non-authoritative provider transcript objects while
 * preserving their immutable database provenance records.
 */
final class PruneProviderTranscripts extends Command
{
    protected $signature =
        'codex:prune-provider-transcripts {--days=}';

    protected $description =
        'Delete expired provider transcript objects while retaining audit metadata';

    /**
     * Delete expired transcript blobs in bounded batches.
     */
    public function handle(): int
    {
        $days = $this->option('days');

        $retentionDays = is_numeric($days)
            ? max(1, (int) $days)
            : max(
                1,
                (int) config(
                    'codex-app-server.persistence.transcript_retention_days',
                    30,
                ),
            );

        $cutoff = now()->subDays(
            $retentionDays,
        );

        $deleted = 0;

        Artifact::query()
            ->where(
                'artifact_type',
                'provider_transcript_chunk',
            )
            ->where(
                'created_at',
                '<',
                $cutoff,
            )
            ->whereNotNull('storage_disk')
            ->whereNotNull('storage_path')
            ->orderBy('created_at')
            ->orderBy('id')
            ->chunkById(
                100,
                function ($artifacts) use (&$deleted): void {
                    foreach ($artifacts as $artifact) {
                        $disk = (string) $artifact
                            ->storage_disk;

                        $path = (string) $artifact
                            ->storage_path;

                        try {
                            $storage = Storage::disk($disk);

                            if ($storage->exists($path)) {
                                $storage->delete($path);
                                $deleted++;
                            }
                        } catch (Throwable $exception) {
                            report($exception);
                        }
                    }
                },
            );

        $this->components->info(
            "Pruned {$deleted} provider transcript object(s).",
        );

        return self::SUCCESS;
    }
}
