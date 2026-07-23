<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Environment\ProductionConfigurationValidator;
use App\Support\Health\ArtifactStorageProbe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

final class CheckEnvironmentCommand extends Command
{
    protected $signature = 'app:check
        {--production : Enforce production-only configuration rules}';

    protected $description =
        'Validate required application configuration and infrastructure.';

    public function __construct(
        private readonly ProductionConfigurationValidator $productionConfigurationValidator,
        private readonly ArtifactStorageProbe $artifactStorageProbe,
    ) {
        parent::__construct();
    }

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
        $this->artifactStorageProbe->probe();

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

        $this->productionConfigurationValidator->validate();

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
