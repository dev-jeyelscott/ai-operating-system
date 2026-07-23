<?php

declare(strict_types=1);

namespace App\Support\Health;

use Illuminate\Support\Facades\Storage;

final class ArtifactStorageProbe
{
    private const SentinelPath = '.aios/health/readiness-probe';

    /**
     * Verify artifact-storage reachability with one read-only object lookup.
     */
    public function probe(): true
    {
        $disk = (string) config('filesystems.artifact', 'local');

        Storage::disk($disk)->exists(self::SentinelPath);

        return true;
    }
}
