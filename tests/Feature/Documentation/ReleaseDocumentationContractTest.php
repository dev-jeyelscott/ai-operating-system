<?php

declare(strict_types=1);

namespace Tests\Feature\Documentation;

use Tests\TestCase;

final class ReleaseDocumentationContractTest extends TestCase
{
    /**
     * Read one required documentation file.
     */
    private function readDocumentation(string $relativePath): string
    {
        $absolutePath = base_path($relativePath);

        $this->assertFileExists(
            $absolutePath,
            "{$relativePath} must exist.",
        );

        $contents = file_get_contents($absolutePath);

        $this->assertIsString(
            $contents,
            "{$relativePath} must contain readable text.",
        );

        return $contents;
    }

    /**
     * Verify the canonical authority hierarchy is complete and ordered.
     */
    public function test_documentation_declares_complete_authority_hierarchy(): void
    {
        $documentation = $this->readDocumentation('docs/README.md');

        $authorities = [
            'Approved project documents and product specifications',
            'Approved ADRs and explicitly approved change decisions',
            'Approved Notion ticket',
            'Repository implementation',
            'Execution logs and generated artifacts',
        ];

        $previousPosition = -1;

        foreach ($authorities as $authority) {
            $position = strpos($documentation, $authority);

            $this->assertNotFalse(
                $position,
                "Missing canonical authority: {$authority}",
            );

            $this->assertGreaterThan(
                $previousPosition,
                $position,
                "Canonical authority is out of order: {$authority}",
            );

            $previousPosition = $position;
        }

        $this->assertStringContainsString(
            'Verified external systems establish observed and verified state',
            $documentation,
        );

        $this->assertStringContainsString(
            'A conflict between authorities must not be silently resolved',
            $documentation,
        );
    }

    /**
     * Verify Start Project support guidance matches the current project UI.
     */
    public function test_start_project_user_guide_matches_the_current_interface(): void
    {
        $guide = $this->readDocumentation('docs/product/user-guide.md');

        $this->assertStringContainsString(
            '**Start this Project**',
            $guide,
        );

        $this->assertStringContainsString(
            '**Starting project...**',
            $guide,
        );

        $this->assertStringContainsString(
            'The current project page does not display an execution identifier',
            $guide,
        );

        $this->assertStringNotContainsString(
            'Capture the displayed execution identifier',
            $guide,
        );
    }

    /**
     * Verify operators are told to provide raw S3 identifiers because the
     * restore command owns CopySource encoding.
     */
    public function test_object_restore_runbook_documents_internal_encoding(): void
    {
        $runbook = $this->readDocumentation(
            'docs/runbooks/backup-and-restore.md',
        );

        $this->assertStringContainsString(
            'Do not pre-encode the S3 object key or version ID',
            $runbook,
        );

        $this->assertStringContainsString(
            'performs the required S3 CopySource encoding internally',
            $runbook,
        );
    }
}
