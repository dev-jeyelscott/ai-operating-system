<?php

declare(strict_types=1);

namespace App\Support\Health;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class HealthService
{
    /**
     * Probe every dependency required before the application accepts work.
     *
     * @return array{
     *     status: 'ok'|'degraded',
     *     release: string,
     *     checks: array<string, array{
     *         status: 'ok'|'failed',
     *         duration_ms: float
     *     }>
     * }
     */
    public function readiness(): array
    {
        $checks = [
            'database' => $this->check(
                fn (): array => DB::select('select 1'),
            ),
            'redis' => $this->check(
                fn (): mixed => Redis::connection()->ping(),
            ),
            'storage' => $this->check(function (): array {
                $disk = (string) config(
                    'filesystems.artifact',
                    'local',
                );

                return Storage::disk($disk)->files();
            }),
        ];

        $ready = true;

        foreach ($checks as $check) {
            if ($check['status'] !== 'ok') {
                $ready = false;
            }
        }

        return [
            'status' => $ready ? 'ok' : 'degraded',
            'release' => (string) config('app.release', 'local'),
            'checks' => $checks,
        ];
    }

    /**
     * Execute one dependency check without disclosing internal exception
     * messages in the public readiness response.
     *
     * @return array{status: 'ok'|'failed', duration_ms: float}
     */
    private function check(Closure $probe): array
    {
        $startedAt = hrtime(true);

        try {
            $probe();

            return [
                'status' => 'ok',
                'duration_ms' => round(
                    (hrtime(true) - $startedAt) / 1_000_000,
                    2,
                ),
            ];
        } catch (Throwable $exception) {
            Log::warning('health.dependency_failed', [
                'exception_class' => $exception::class,
            ]);

            return [
                'status' => 'failed',
                'duration_ms' => round(
                    (hrtime(true) - $startedAt) / 1_000_000,
                    2,
                ),
            ];
        }
    }
}
