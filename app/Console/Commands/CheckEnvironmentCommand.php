<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class CheckEnvironmentCommand extends Command
{
    protected $signature = 'app:check
        {--production : Enforce production-only configuration rules}';

    protected $description =
        'Validate required application configuration and infrastructure.';

    /**
     * Execute every environment check and return a failing exit code when
     * one or more required dependencies are unavailable.
     */
    public function handle(): int
    {
        $checks = [
            'Application key' => fn (): true => $this->ensure(
                filled(config('app.key')),
                'APP_KEY is missing.',
            ),
            'Required PHP extensions' => fn (): true => $this->checkExtensions(),
            'Database connection' => fn (): true => $this->checkDatabase(),
            'Redis connection' => fn (): true => $this->checkRedis(),
            'Artifact storage' => fn (): true => $this->checkArtifactStorage(),
            'Queue configuration' => fn (): true => $this->ensure(
                config('queue.default') === 'redis',
                'QUEUE_CONNECTION must be redis.',
            ),
            'Cache configuration' => fn (): true => $this->ensure(
                config('cache.default') === 'redis',
                'CACHE_STORE must be redis.',
            ),
            'Production safety' => fn (): true => $this->checkProductionSafety(),
        ];

        $failures = 0;

        foreach ($checks as $name => $check) {
            try {
                $check();
                $this->components->info($name);
            } catch (Throwable $exception) {
                $failures++;

                $this->components->error(
                    sprintf('%s: %s', $name, $exception->getMessage()),
                );
            }
        }

        return $failures === 0
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * Verify extensions required by PostgreSQL, Redis, and Horizon workers.
     */
    private function checkExtensions(): true
    {
        foreach (['pdo_pgsql', 'redis', 'pcntl', 'posix'] as $extension) {
            $this->ensure(
                extension_loaded($extension),
                sprintf('PHP extension %s is missing.', $extension),
            );
        }

        return true;
    }

    /**
     * Verify that PostgreSQL accepts a trivial query.
     */
    private function checkDatabase(): true
    {
        DB::select('select 1');

        return true;
    }

    /**
     * Verify that Redis responds to a PING command.
     */
    private function checkRedis(): true
    {
        Redis::connection()->ping();

        return true;
    }

    /**
     * Verify that the configured artifact disk can reach its backend.
     */
    private function checkArtifactStorage(): true
    {
        $disk = (string) config('filesystems.artifact', 'local');

        Storage::disk($disk)->files();

        return true;
    }

    /**
     * Enforce settings that must never be unsafe in production.
     */
    private function checkProductionSafety(): true
    {
        if (! $this->option('production')) {
            return true;
        }

        $this->ensure(
            app()->environment('production'),
            'APP_ENV must be production.',
        );

        $this->ensure(
            config('app.debug') === false,
            'APP_DEBUG must be false.',
        );

        $this->ensure(
            config('mail.default') !== 'log',
            'A real production mailer is required.',
        );

        $this->ensure(
            config('mail.mailers.smtp.host') !== 'mailpit',
            'Mailpit is local-only.',
        );

        $this->ensure(
            blank(config('filesystems.disks.s3.endpoint')),
            'AWS_ENDPOINT must be empty when using Amazon S3.',
        );

        $reverbKey = (string) config(
            'broadcasting.connections.reverb.key',
            '',
        );

        $this->ensure(
            ! str_starts_with($reverbKey, 'local-'),
            'Local Reverb credentials are forbidden in production.',
        );

        return true;
    }

    /**
     * Throw a readable validation failure when a requirement is not met.
     */
    private function ensure(bool $condition, string $message): true
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }

        return true;
    }
}
