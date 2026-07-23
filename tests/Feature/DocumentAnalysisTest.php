<?php

declare(strict_types=1);
use App\Application\Documents\AnalyzeDocumentVersion;
use App\Domain\Documents\DocumentClassification;
use App\Domain\Documents\DocumentStatus;
use App\Models\DocumentVersion;

test('deterministic analysis persists stable summaries classifications conflicts and gaps', function (): void {
    $version = DocumentVersion::factory()->create(['status' => DocumentStatus::Parsed, 'parsed_content' => 'Architecture [conflict] [gap]']);
    app(AnalyzeDocumentVersion::class)->handle($version->id, 42);
    expect($version->fresh())->classification->toBe(DocumentClassification::Architecture)->analysis_summary->toContain('Deterministic summary #42')->analysis_conflicts->toBe(['Conflicting instruction detected.'])->analysis_gaps->toBe(['Required detail is missing.']);
});
test('analysis ignores documents that have not parsed', function (): void {
    $version = DocumentVersion::factory()->create(['status' => DocumentStatus::ScanApproved]);
    app(AnalyzeDocumentVersion::class)->handle($version->id, 7);
    expect($version->fresh()->analysis_summary)->toBeNull();
});
