<?php

declare(strict_types=1);

namespace Tests\Feature\Documentation;

use Tests\TestCase;

final class CodexExecutionIsolationDocumentationTest extends TestCase
{
    private const string ADR_PATH =
        'docs/adr/0008-use-codex-app-server-with-laravel-managed-isolated-execution.md';

    private const string THREAT_MODEL_PATH =
        'docs/security/codex-execution-threat-model-appendix.md';

    private const string EVIDENCE_PATH =
        'docs/evidence/aios-241-codex-execution-isolation-adr.md';

    /**
     * Read a required repository documentation file.
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
     * Verify the accepted ADR records the complete control-plane and isolation
     * decision required by AIOS-241.
     */
    public function test_codex_adr_records_required_architecture_decisions(): void
    {
        $adr = $this->readDocumentation(self::ADR_PATH);

        $requiredStatements = [
            '- Status: Accepted',
            'Codex App Server over stdio',
            'Laravel remains authoritative for',
            'Codex must never',
            'One Laravel-managed execution job owns one Codex process',
            'Each attempt receives an isolated `CODEX_HOME`',
            'Network access is denied by default',
            'Provider output begins as Reported evidence',
            'Normal pull requests target `develop`',
            'Codex never merges a pull request',
            'Simulation remains registered and testable',
            'Cancellation is idempotent and Laravel-owned',
            'Rejected alternatives',
            'Security entry gates',
            'Rollback',
            'Review triggers',
            '```mermaid',
        ];

        foreach ($requiredStatements as $statement) {
            $this->assertStringContainsString(
                $statement,
                $adr,
                "The Codex ADR is missing required content: {$statement}",
            );
        }
    }

    /**
     * Verify every approved post-MVP follow-up ticket is traceable to the ADR.
     */
    public function test_codex_adr_traces_aios_242_through_aios_289(): void
    {
        $adr = $this->readDocumentation(self::ADR_PATH);

        for ($ticket = 242; $ticket <= 289; $ticket++) {
            $this->assertStringContainsString(
                "AIOS-{$ticket}",
                $adr,
                "AIOS-{$ticket} must be traceable to ADR-0008.",
            );
        }
    }

    /**
     * Verify the threat appendix covers the critical Codex trust boundaries.
     */
    public function test_codex_threat_model_covers_critical_execution_risks(): void
    {
        $threatModel = $this->readDocumentation(
            self::THREAT_MODEL_PATH,
        );

        $requiredThreats = [
            'CDX-001',
            'Cross-tenant provider context',
            'CDX-003',
            'Malformed protocol message',
            'CDX-004',
            'Provider process escapes',
            'CDX-006',
            'Provider credential leaks',
            'CDX-007',
            'unrestricted network access',
            'CDX-010',
            'Forged or replayed provider approval',
            'CDX-013',
            'Orphan process',
            'CDX-015',
            'Retry duplicates branch',
            'CDX-017',
            'Layer 3 inherits Layer 2',
            'CDX-024',
            'targeting `main`',
            'No unresolved Critical or High finding',
        ];

        foreach ($requiredThreats as $requiredThreat) {
            $this->assertStringContainsString(
                $requiredThreat,
                $threatModel,
                "The Codex threat model is missing: {$requiredThreat}",
            );
        }
    }

    /**
     * Verify the repository documentation index exposes the new architecture
     * and security documents.
     */
    public function test_documentation_index_links_codex_architecture_documents(): void
    {
        $index = $this->readDocumentation('docs/README.md');

        $this->assertStringContainsString(
            'adr/0008-use-codex-app-server-with-laravel-managed-isolated-execution.md',
            $index,
        );

        $this->assertStringContainsString(
            'security/codex-execution-threat-model-appendix.md',
            $index,
        );
    }

    /**
     * Verify AIOS-241 retains an explicit human architecture review record.
     */
    public function test_codex_adr_has_human_review_evidence(): void
    {
        $evidence = $this->readDocumentation(self::EVIDENCE_PATH);

        $requiredEvidence = [
            'Decision: Accepted',
            'Architecture reviewer:',
            'Security reviewer:',
            'Product owner:',
            'Review reference:',
            'No runtime Codex provider was enabled',
            'No repository write capability was enabled',
            'No merge or deployment capability was enabled',
            'composer ci:check',
        ];

        foreach ($requiredEvidence as $requiredItem) {
            $this->assertStringContainsString(
                $requiredItem,
                $evidence,
                "The AIOS-241 evidence record is missing: {$requiredItem}",
            );
        }
    }
}
