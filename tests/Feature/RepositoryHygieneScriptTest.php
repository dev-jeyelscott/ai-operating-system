<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class RepositoryHygieneScriptTest extends TestCase
{
    private Filesystem $filesystem;

    private string $sandbox;

    /**
     * Create an isolated Git repository with a valid hygiene baseline.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem();
        $this->sandbox = sys_get_temp_dir()
            .'/aios-repository-hygiene-'
            .bin2hex(random_bytes(8));

        $this->filesystem->ensureDirectoryExists($this->sandbox.'/bin');

        $this->filesystem->copy(
            base_path('bin/check-repository-hygiene'),
            $this->sandbox.'/bin/check-repository-hygiene',
        );

        chmod($this->sandbox.'/bin/check-repository-hygiene', 0755);

        $this->writeFile(
            'docs/security/threat-model.md',
            "# Threat model\n\nRepository-hygiene test fixture.\n",
        );

        $this->writeFile(
            'docs/evidence/phase-0.md',
            "# Phase 0 evidence\n\nNo unresolved placeholders.\n",
        );

        $this->writeFile(
            'tests/Feature/Security/OrganizationIsolationTest.php',
            "<?php\n\ndeclare(strict_types=1);\n",
        );

        $this->writeFile(
            'tests/Unit/Policies/ProjectPermissionMatrixTest.php',
            "<?php\n\ndeclare(strict_types=1);\n",
        );

        $this->runCommand(['git', 'init', '--quiet']);
        $this->runCommand(['git', 'config', 'user.name', 'AIOS Test']);
        $this->runCommand([
            'git',
            'config',
            'user.email',
            'aios-test@example.test',
        ]);
        $this->runCommand(['git', 'config', 'commit.gpgsign', 'false']);
        $this->runCommand(['git', 'add', '.']);
        $this->runCommand([
            'git',
            'commit',
            '--quiet',
            '-m',
            'test: create hygiene fixture',
        ]);
    }

    /**
     * Remove the isolated repository after every test.
     */
    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->sandbox);

        parent::tearDown();
    }

    public function test_clean_repository_passes(): void
    {
        $process = $this->runHygieneCheck();

        $this->assertTrue(
            $process->isSuccessful(),
            $process->getErrorOutput(),
        );

        $this->assertStringContainsString(
            'Repository hygiene checks passed.',
            $process->getOutput(),
        );
    }

    public function test_tracked_backup_file_fails_with_actionable_path(): void
    {
        $this->writeFile(
            'vite.config.ts.backup',
            'temporary backup',
        );

        $this->runCommand([
            'git',
            'add',
            '--force',
            'vite.config.ts.backup',
        ]);

        $process = $this->runHygieneCheck();

        $this->assertFalse($process->isSuccessful());

        $this->assertStringContainsString(
            'vite.config.ts.backup',
            $process->getErrorOutput(),
        );
    }

    public function test_zero_byte_required_artifact_fails_with_actionable_path(): void
    {
        $this->writeFile(
            'docs/security/empty-security-artifact.md',
            '',
        );

        $process = $this->runHygieneCheck();

        $this->assertFalse($process->isSuccessful());

        $this->assertStringContainsString(
            'docs/security/empty-security-artifact.md',
            $process->getErrorOutput(),
        );
    }

    public function test_each_approved_evidence_placeholder_pattern_fails(): void
    {
        $placeholders = [
            'Add the pull-request links here.',
            'Document any accepted non-blocking risk here.',
            'Merged pull requests: <number>',
            'Release scope: <scope>',
            'Verification state: TBD',
        ];

        foreach ($placeholders as $placeholder) {
            $this->writeFile(
                'docs/evidence/phase-0.md',
                "# Phase 0 evidence\n\n{$placeholder}\n",
            );

            $process = $this->runHygieneCheck();

            $this->assertFalse(
                $process->isSuccessful(),
                "Expected placeholder to fail: {$placeholder}",
            );

            $this->assertStringContainsString(
                'docs/evidence/phase-0.md:3:',
                $process->getErrorOutput(),
            );
        }
    }

    /**
     * Execute the hygiene script inside the isolated repository.
     */
    private function runHygieneCheck(): Process
    {
        $process = new Process(
            ['bash', 'bin/check-repository-hygiene'],
            $this->sandbox,
        );

        $process->run();

        return $process;
    }

    /**
     * Execute a fixture setup command and fail immediately on non-zero exit.
     *
     * @param list<string> $command
     */
    private function runCommand(array $command): void
    {
        $process = new Process($command, $this->sandbox);

        $process->mustRun();
    }

    /**
     * Write one fixture file and create its parent directories when needed.
     */
    private function writeFile(
        string $relativePath,
        string $contents,
    ): void {
        $absolutePath = $this->sandbox.'/'.$relativePath;

        $this->filesystem->ensureDirectoryExists(
            dirname($absolutePath),
        );

        $this->filesystem->put(
            $absolutePath,
            $contents,
        );
    }
}
