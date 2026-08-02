<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class OperationalScriptsTest extends TestCase
{
    /**
     * Return every operational shell script that must remain valid.
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
            'bin/deploy-release',
            'bin/rollback-release',
        ];
    }

    /**
     * Verify every operational shell script has valid Bash syntax.
     */
    public function test_operational_scripts_have_valid_bash_syntax(): void
    {
        foreach ($this->scripts() as $relativePath) {
            $process = new Process([
                'bash',
                '-n',
                base_path($relativePath),
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
            );
        }
    }

    /**
     * Verify deployment uses forward isolated migrations.
     */
    public function test_deployment_uses_forward_isolated_migrations(): void
    {
        $deployment = file_get_contents(
            base_path('bin/deploy-release'),
        );

        $this->assertIsString($deployment);
        $this->assertStringContainsString(
            'run_release_artisan migrate',
            $deployment,
        );
        $this->assertStringContainsString(
            '--force',
            $deployment,
        );
        $this->assertStringContainsString(
            '--isolated',
            $deployment,
        );
    }

    /**
     * Verify operational scripts never automate destructive migration rollback.
     */
    public function test_operational_scripts_do_not_rollback_migrations(): void
    {
        foreach ($this->scripts() as $relativePath) {
            $contents = file_get_contents(
                base_path($relativePath),
            );

            $this->assertIsString($contents);
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
     * Verify production promotion depends on staging and reuses one artifact.
     */
    public function test_production_promotion_reuses_the_staging_artifact(): void
    {
        $workflow = file_get_contents(
            base_path('.github/workflows/deployment.yml'),
        );

        $this->assertIsString($workflow);
        $this->assertStringContainsString(
            'deploy_staging',
            $workflow,
        );
        $this->assertStringContainsString(
            'deploy_production',
            $workflow,
        );
        $this->assertStringContainsString(
            'needs.build_release.outputs.artifact_id',
            $workflow,
        );
        $this->assertStringContainsString(
            '- deploy_staging',
            $workflow,
        );
        $this->assertStringContainsString(
            'name: production',
            $workflow,
        );
    }

    /**
     * Verify the disaster-recovery workflow covers both storage systems.
     */
    public function test_disaster_recovery_workflow_runs_the_complete_rehearsal(): void
    {
        $workflow = file_get_contents(
            base_path('.github/workflows/disaster-recovery.yml'),
        );

        $this->assertIsString($workflow);
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
