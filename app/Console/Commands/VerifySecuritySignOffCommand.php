<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Security\SecuritySignOffValidator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature(
    'security:sign-off
        {--manifest=docs/security/security-review.json : Repository-relative or absolute security review manifest path}
        {--candidate-sha= : Required full 40-character release-candidate Git SHA}',
)]
#[Description(
    'Verify threat-model evidence and reject unresolved Critical or High findings.',
)]
final class VerifySecuritySignOffCommand extends Command
{
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
        $candidateShaOption = $this->option('candidate-sha');

        if (
            ! is_string($candidateShaOption)
            || preg_match('/\A[0-9a-fA-F]{40}\z/', trim($candidateShaOption)) !== 1
        ) {
            $this->components->error(
                'The candidate-sha option must be a full 40-character Git SHA.',
            );

            return self::INVALID;
        }

        $candidateSha = strtolower(trim($candidateShaOption));
        $manifestOption = $this->option('manifest');

        if (! is_string($manifestOption) || trim($manifestOption) === '') {
            $this->components->error(
                'The manifest option must contain a valid file path.',
            );

            return self::INVALID;
        }

        $manifestPath = $this->resolveManifestPath(
            trim($manifestOption),
        );

        $result = $this->validator->validate($manifestPath, $candidateSha);

        $this->table(
            ['Field', 'Value'],
            [
                ['Decision', $result->decision],
                [
                    'Reviewed commit',
                    $result->reviewedCommit !== ''
                        ? $result->reviewedCommit
                        : 'Not provided',
                ],
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
