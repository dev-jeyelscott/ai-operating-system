<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class OperationalScriptsTest extends TestCase
{
    /**
     * Return every operational shell script currently included in the
     * backup, restoration, and disaster-recovery scope.
     *
     * @return list<string>
     */
    private function scripts(): array
    {
        return [
            'bin/lib/operations.sh',
            'bin/backup-database',
            'bin/restore-database',
            'bin/restore-object-version',
            'bin/dr-rehearsal',
        ];
    }

    /**
     * Read a required repository file and fail with a clear assertion when
     * the expected operational artifact is missing.
     */
    private function readRepositoryFile(string $relativePath): string
    {
        $absolutePath = base_path($relativePath);

        $this->assertFileExists(
            $absolutePath,
            "{$relativePath} must exist for the current operations scope.",
        );

        $contents = file_get_contents($absolutePath);

        $this->assertIsString(
            $contents,
            "{$relativePath} must contain readable text.",
        );

        return $contents;
    }

    /**
     * Verify every current operational shell script has valid Bash syntax.
     */
    public function test_operational_scripts_have_valid_bash_syntax(): void
    {
        foreach ($this->scripts() as $relativePath) {
            $absolutePath = base_path($relativePath);

            $this->assertFileExists(
                $absolutePath,
                "{$relativePath} must exist before Bash validation.",
            );

            $process = new Process([
                'bash',
                '-n',
                $absolutePath,
            ]);

            $process->run();

            $this->assertTrue(
                $process->isSuccessful(),
                sprintf(
                    "%s failed Bash syntax validation:\n%s",
                    $relativePath,
                    $process->getErrorOutput(),
                ),
            );
        }
    }

    /**
     * Verify every executable operational command exposes usable help.
     */
    public function test_operational_commands_expose_help(): void
    {
        foreach (array_slice($this->scripts(), 1) as $relativePath) {
            $absolutePath = base_path($relativePath);

            $this->assertFileExists(
                $absolutePath,
                "{$relativePath} must exist before help validation.",
            );

            $process = new Process([
                'bash',
                $absolutePath,
                '--help',
            ]);

            $process->run();

            $this->assertTrue(
                $process->isSuccessful(),
                sprintf(
                    "%s failed its help command:\n%s",
                    $relativePath,
                    $process->getErrorOutput(),
                ),
            );

            $this->assertStringContainsString(
                'Usage:',
                $process->getOutput(),
                "{$relativePath} must provide usage information.",
            );
        }
    }

    /**
     * Verify recovery scripts do not contain destructive Laravel database
     * commands that could erase or reverse application data.
     */
    public function test_operational_scripts_do_not_use_destructive_database_commands(): void
    {
        foreach ($this->scripts() as $relativePath) {
            $contents = $this->readRepositoryFile($relativePath);

            $this->assertStringNotContainsString(
                'migrate:rollback',
                $contents,
                "{$relativePath} must not automate schema rollback.",
            );

            $this->assertStringNotContainsString(
                'migrate:fresh',
                $contents,
                "{$relativePath} must not rebuild the database.",
            );

            $this->assertStringNotContainsString(
                'db:wipe',
                $contents,
                "{$relativePath} must not wipe the database.",
            );
        }
    }

    /**
     * Verify the disaster-recovery workflow covers PostgreSQL restoration,
     * MinIO object restoration, rehearsal execution, and evidence retention.
     */
    public function test_disaster_recovery_workflow_runs_the_complete_rehearsal(): void
    {
        $workflow = $this->readRepositoryFile(
            '.github/workflows/disaster-recovery.yml',
        );

        $this->assertStringContainsString(
            'postgres:',
            $workflow,
        );

        $this->assertStringContainsString(
            'minio/minio:',
            $workflow,
        );

        $this->assertStringContainsString(
            'bash bin/dr-rehearsal',
            $workflow,
        );

        $this->assertStringContainsString(
            'disaster-recovery-evidence',
            $workflow,
        );
    }
}
