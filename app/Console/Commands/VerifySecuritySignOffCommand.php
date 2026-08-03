<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Security\SecuritySignOffValidator;
use Illuminate\Console\Command;

/**
 * Verifies the AIOS-150 security review and release sign-off.
 */
final class VerifySecuritySignOffCommand extends Command
{
    protected $signature = 'security:sign-off
        {--manifest=docs/security/security-review.json : Repository-relative or absolute security review manifest path}';

    protected $description =
        'Verify threat-model evidence and reject unresolved Critical or High findings.';

    /**
     * Inject the deterministic security sign-off validator.
     */
    public function __construct(
        private readonly SecuritySignOffValidator $validator,
    ) {
        parent::__construct();
    }

    /**
     * Validate the manifest and return a CI-compatible exit status.
     */
    public function handle(): int
    {
        $manifest = trim((string) $this->option('manifest'));
        $manifestPath = $this->resolveManifestPath($manifest);

        $result = $this->validator->validate($manifestPath);

        $this->table(
            ['Field', 'Value'],
            [
                ['Decision', $result->decision],
                ['Reviewed commit', $result->reviewedCommit ?: 'Not provided'],
                ['Dependencies', (string) $result->dependencyCount],
                ['Findings', (string) $result->findingCount],
                ['Violations', (string) count($result->violations)],
            ],
        );

        if (! $result->passed()) {
            $this->components->error('Security sign-off failed.');

            foreach ($result->violations as $violation) {
                $this->line(sprintf(' - %s', $violation));
            }

            return self::FAILURE;
        }

        $this->components->info('Security sign-off passed.');

        return self::SUCCESS;
    }

    /**
     * Resolve a manifest path without invoking a shell.
     */
    private function resolveManifestPath(string $manifest): string
    {
        if (
            str_starts_with($manifest, DIRECTORY_SEPARATOR)
            || preg_match('/\A[A-Za-z]:[\\\\\/]/', $manifest) === 1
        ) {
            return $manifest;
        }

        return base_path($manifest);
    }
}
