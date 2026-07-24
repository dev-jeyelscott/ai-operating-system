<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Documents\DocumentClassification;
use App\Domain\Documents\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DocumentVersion> */
final class DocumentVersionFactory extends Factory
{
    /** @var class-string<DocumentVersion> */
    protected $model = DocumentVersion::class;

    /**
     * Define a valid parser-supported document version.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'version' => 1,
            'original_filename' => fake()->bothify('document-####.txt'),
            'media_type' => 'text/plain',
            'byte_size' => fake()->numberBetween(1_024, 1_048_576),
            'storage_disk' => 'documents',
            'storage_path' => sprintf(
                'documents/%s',
                Str::ulid(),
            ),
            'checksum_sha256' => hash('sha256', fake()->uuid()),
            'status' => DocumentStatus::Uploaded,
            'classification' => DocumentClassification::Unclassified,
            'parser_name' => null,
            'parser_version' => null,
            'parsing_started_at' => null,
            'parsed_at' => null,
            'failure_code' => null,
            'failure_message' => null,
            'supersedes_document_version_id' => null,
            'parsed_content' => null,
            'analyzer_name' => null,
            'analyzer_version' => null,
            'analysis_seed' => null,
            'analysis_started_at' => null,
            'analysis_completed_at' => null,
            'analysis_summary' => null,
            'analysis_conflicts' => null,
            'analysis_gaps' => null,
            'analysis_flags' => null,
        ];
    }

    /**
     * Mark a document version as actively parsing.
     */
    public function processing(): static
    {
        return $this->state(fn (): array => [
            'status' => DocumentStatus::Parsing,
            'parser_name' => 'mvp-parser',
            'parser_version' => '1.0.0',
            'parsing_started_at' => now(),
        ]);
    }

    /**
     * Mark a document as fully analyzed and eligible for review.
     */
    public function classified(
        DocumentClassification $classification =
            DocumentClassification::Specification,
    ): static {
        return $this->state(fn (): array => [
            'status' => DocumentStatus::NeedsReview,
            'classification' => $classification,
            'parser_name' => 'mvp-parser',
            'parser_version' => '1.0.0',
            'parsing_started_at' => now()->subSecond(),
            'parsed_at' => now(),
            'parsed_content' => 'Analyzed fixture content.',
            'analyzer_name' => 'deterministic-document-analyzer',
            'analyzer_version' => '1.0.0',
            'analysis_seed' => 42,
            'analysis_started_at' => now(),
            'analysis_completed_at' => now(),
            'analysis_summary' => 'Deterministic fixture summary.',
            'analysis_conflicts' => [],
            'analysis_gaps' => [],
            'analysis_flags' => [],
            'failure_code' => null,
            'failure_message' => null,
        ]);
    }

    /**
     * Mark a fully analyzed document version as approved.
     */
    public function approved(): static
    {
        return $this
            ->classified()
            ->state(fn (): array => [
                'status' => DocumentStatus::Approved,
            ]);
    }

    /**
     * Create a version linked to the version it supersedes.
     */
    public function superseding(
        DocumentVersion $documentVersion,
    ): static {
        return $this->state(fn (): array => [
            'document_id' => $documentVersion->document_id,
            'version' => $documentVersion->version + 1,
            'status' => DocumentStatus::Superseded,
            'supersedes_document_version_id' => $documentVersion->id,
        ]);
    }

    /**
     * Mark a parsed document as waiting for deterministic analysis.
     */
    public function analysisPending(): static
    {
        return $this->state(fn (): array => [
            'status' => DocumentStatus::AnalysisPending,
            'parser_name' => 'mvp-parser',
            'parser_version' => '1.0.0',
            'parsing_started_at' => now()->subSecond(),
            'parsed_at' => now(),
            'parsed_content' => 'Pending analysis fixture.',
            'analysis_conflicts' => [],
            'analysis_gaps' => [],
            'analysis_flags' => [],
        ]);
    }

    /**
     * Mark a document as actively analyzing.
     */
    public function analyzing(): static
    {
        return $this->analysisPending()->state(fn (): array => [
            'status' => DocumentStatus::Analyzing,
            'analyzer_name' => 'deterministic-document-analyzer',
            'analyzer_version' => '1.0.0',
            'analysis_seed' => 42,
            'analysis_started_at' => now(),
        ]);
    }

    /**
     * Mark deterministic analysis as terminally failed.
     */
    public function analysisFailed(): static
    {
        return $this->analyzing()->state(fn (): array => [
            'status' => DocumentStatus::AnalysisFailed,
            'analysis_completed_at' => null,
            'failure_code' => 'analysis_failed',
            'failure_message' => 'The document analysis could not be completed.',
        ]);
    }
}
