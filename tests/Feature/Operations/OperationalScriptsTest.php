<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class OperationalScriptsTest extends TestCase
{
    /**
     * Return every operational shell script included in backup, restoration,
     * and disaster-recovery validation.
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
     * Read a required repository file.
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
            $process = new Process([
                'bash',
                base_path($relativePath),
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
     * Verify recovery scripts cannot invoke destructive Laravel database
     * reset or rollback commands.
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
     * Verify the disaster-recovery workflow covers PostgreSQL, object storage,
     * rehearsal execution, and retained evidence.
     */
    public function test_disaster_recovery_workflow_runs_the_complete_rehearsal(): void
    {
        $workflow = $this->readRepositoryFile(
            '.github/workflows/disaster-recovery.yml',
        );

        $this->assertStringContainsString('postgres:', $workflow);
        $this->assertStringContainsString('minio/minio:', $workflow);
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
     * Verify rehearsal evidence stays below the repository operation root.
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
     * Verify backup and restore roots use safe parameter expansions.
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
     * Verify PostgreSQL custom backups stream through host redirection.
     */
    public function test_database_backup_does_not_write_an_archive_inside_the_container(): void
    {
        $script = $this->readRepositoryFile('bin/backup-database');

        $this->assertStringNotContainsString('--file=-', $script);
        $this->assertStringContainsString('> "$partial_dump"', $script);
    }

    /**
     * Verify archive inspection and restore read from standard input without a
     * literal dash filename.
     */
    public function test_postgresql_archive_commands_do_not_pass_a_literal_dash_filename(): void
    {
        $backup = $this->readRepositoryFile('bin/backup-database');
        $restore = $this->readRepositoryFile('bin/restore-database');

        $this->assertStringNotContainsString(
            "--list \\\n    - \\",
            $backup,
        );

        $this->assertStringNotContainsString(
            "--list \\\n    - \\",
            $restore,
        );

        $this->assertStringNotContainsString(
            "--no-privileges \\\n    - \\",
            $restore,
        );
    }

    /**
     * Verify versioned S3 CopySource generation encodes reserved characters
     * while preserving logical object-key path separators.
     */
    public function test_versioned_s3_copy_source_is_url_encoded(): void
    {
        $process = new Process(
            [
                'bash',
                '-c',
                <<<'BASH'
source "$OPERATIONS_SCRIPT"
build_versioned_s3_copy_source \
    "$TEST_BUCKET" \
    "$TEST_KEY" \
    "$TEST_VERSION"
BASH,
            ],
            base_path(),
            [
                'OPERATIONS_SCRIPT' => base_path('bin/lib/operations.sh'),
                'TEST_BUCKET' => 'recovery-bucket',
                'TEST_KEY' => 'recovery/encoded key/plus+hash#question?/résumé.txt',
                'TEST_VERSION' => '3/L4+k?=',
            ],
        );

        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            $process->getErrorOutput(),
        );

        $this->assertSame(
            'recovery-bucket/recovery/encoded%20key/plus%2Bhash%23question%3F/r%C3%A9sum%C3%A9.txt?versionId=3%2FL4%2Bk%3F%3D',
            trim($process->getOutput()),
        );
    }

    /**
     * Verify the restore command delegates CopySource construction to the
     * shared encoder and validates copy response metadata.
     */
    public function test_object_version_restore_uses_encoded_copy_source_and_records_version_ids(): void
    {
        $script = $this->readRepositoryFile('bin/restore-object-version');

        $this->assertStringContainsString(
            'build_versioned_s3_copy_source',
            $script,
        );

        $this->assertStringContainsString(
            '--copy-source "$copy_source"',
            $script,
        );

        $this->assertStringContainsString(
            '.CopySourceVersionId // empty',
            $script,
        );

        $this->assertStringContainsString(
            '.VersionId // empty',
            $script,
        );

        $this->assertStringNotContainsString(
            'readonly copy_source="${bucket}/${object_key}?versionId=${version_id}"',
            $script,
        );
    }
}
