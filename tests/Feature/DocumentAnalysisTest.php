<?php

declare(strict_types=1);

use App\Application\Documents\AnalyzeDocumentVersion;
use App\Domain\Documents\DocumentClassification;
use App\Domain\Documents\DocumentStatus;
use App\Jobs\AnalyzeDocumentVersionJob;
use App\Models\DocumentVersion;

test(
    'deterministic analysis persists results provenance and review eligibility',
    function (): void {
        $version = DocumentVersion::factory()
            ->analysisPending()
            ->create([
                'parsed_content' => 'Architecture [conflict] [gap]',
            ]);

        app(AnalyzeDocumentVersion::class)
            ->handle($version->id, 42);

        $analyzed = $version->fresh();

        expect($analyzed)
            ->status->toBe(DocumentStatus::NeedsReview)
            ->classification->toBe(
                DocumentClassification::Architecture,
            )
            ->analysis_summary->toContain(
                'Deterministic summary #42',
            )
            ->analysis_conflicts->toBe([
                'Conflicting instruction detected.',
            ])
            ->analysis_gaps->toBe([
                'Required detail is missing.',
            ])
            ->analysis_flags->toBe([])
            ->analyzer_name->toBe(
                'deterministic-document-analyzer',
            )
            ->analyzer_version->toBe('1.0.0')
            ->analysis_seed->toBe(42)
            ->analysis_started_at->not->toBeNull()
            ->analysis_completed_at->not->toBeNull();

        expect($analyzed->isReadyForReview())->toBeTrue();
    },
);

test(
    'completed analysis is idempotent',
    function (): void {
        $version = DocumentVersion::factory()
            ->analysisPending()
            ->create([
                'parsed_content' => 'Architecture baseline.',
            ]);

        app(AnalyzeDocumentVersion::class)
            ->handle($version->id, 42);

        $completedAt = $version
            ->fresh()
            ->analysis_completed_at;

        app(AnalyzeDocumentVersion::class)
            ->handle($version->id, 42);

        expect(
            $version
                ->fresh()
                ->analysis_completed_at
                ?->equalTo($completedAt),
        )->toBeTrue();
    },
);

test(
    'analysis ignores documents outside pending or running states',
    function (): void {
        $version = DocumentVersion::factory()->create([
            'status' => DocumentStatus::ScanApproved,
        ]);

        app(AnalyzeDocumentVersion::class)
            ->handle($version->id, 7);

        expect($version->fresh())
            ->status->toBe(DocumentStatus::ScanApproved)
            ->analysis_summary->toBeNull();
    },
);

test(
    'analysis jobs have bounded retries and record terminal failure',
    function (): void {
        $version = DocumentVersion::factory()
            ->analyzing()
            ->create();

        $job = new AnalyzeDocumentVersionJob($version->id);

        expect($job)
            ->tries->toBe(3)
            ->backoff->toBe([5, 30, 120])
            ->timeout->toBe(30);

        $job->failed(
            new RuntimeException('Analyzer unavailable.'),
        );

        expect($version->fresh())
            ->status->toBe(DocumentStatus::AnalysisFailed)
            ->failure_code->toBe('analysis_failed')
            ->analysis_completed_at->toBeNull();
    },
);
