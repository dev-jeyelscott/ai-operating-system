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

    /**
     * Verify rehearsal evidence is written below the repository operation root.
     */
    public function test_disaster_recovery_rehearsal_declares_a_valid_evidence_path(): void
    {
        $script = $this->readRepositoryFile('bin/dr-rehearsal');

        $this->assertStringContainsString(
            'readonly evidence_directory="${PROJECT_ROOT}/storage/app/operations/dr-rehearsals/${suffix}"',
            $script,
        );
    }

    /**
     * Verify backup and restore evidence paths resolve from one parameter
     * expansion instead of a runtime command substitution.
     */
    public function test_operational_scripts_declare_valid_evidence_roots(): void
    {
        $this->assertStringContainsString(
            'readonly backup_root="${BACKUP_ROOT:-${PROJECT_ROOT}/storage/app/operations/backups/database}"',
            $this->readRepositoryFile('bin/backup-database'),
        );

        $this->assertStringContainsString(
            'readonly evidence_root="${RESTORE_EVIDENCE_ROOT:-${PROJECT_ROOT}/storage/app/operations/restores}"',
            $this->readRepositoryFile('bin/restore-database'),
        );

        $this->assertStringContainsString(
            'readonly evidence_root="${OBJECT_RESTORE_EVIDENCE_ROOT:-${PROJECT_ROOT}/storage/app/operations/object-restores}"',
            $this->readRepositoryFile('bin/restore-object-version'),
        );
    }

    /**
     * Verify PostgreSQL custom backups stream through the host redirection.
     */
    public function test_database_backup_does_not_write_an_archive_inside_the_container(): void
    {
        $script = $this->readRepositoryFile('bin/backup-database');

        $this->assertStringNotContainsString('--file=-', $script);
        $this->assertStringContainsString('> "$partial_dump"', $script);
    }

    /**
     * Verify archive checks and restores read from standard input when no
     * archive filename is supplied to pg_restore.
     */
    public function test_postgresql_archive_commands_do_not_pass_a_literal_dash_filename(): void
    {
        $backup = $this->readRepositoryFile('bin/backup-database');
        $restore = $this->readRepositoryFile('bin/restore-database');

        $this->assertStringNotContainsString("--list \\\n    - \\", $backup);
        $this->assertStringNotContainsString("--list \\\n    - \\", $restore);
        $this->assertStringNotContainsString("--no-privileges \\\n    - \\", $restore);
    }

    /**
     * Verify the AWS CLI receives the raw versioned copy source so it can
     * construct the S3 CopySource header without double-encoding key slashes.
     */
    public function test_object_version_restore_uses_a_raw_versioned_copy_source(): void
    {
        $script = $this->readRepositoryFile('bin/restore-object-version');

        $this->assertStringContainsString(
            'readonly copy_source="${bucket}/${object_key}?versionId=${version_id}"',
            $script,
        );
        $this->assertStringContainsString(
            '--copy-source "$copy_source"',
            $script,
        );
        $this->assertStringNotContainsString('encoded_copy_source', $script);
    }
}
