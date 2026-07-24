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
     * Mark a document as parsed and classified.
     */
    public function classified(
        DocumentClassification $classification =
            DocumentClassification::Specification,
    ): static {
        return $this->state(fn (): array => [
            'status' => DocumentStatus::Parsed,
            'classification' => $classification,
            'parser_name' => 'mvp-parser',
            'parser_version' => '1.0.0',
            'parsed_at' => now(),
        ]);
    }

    /**
     * Mark the document version as approved.
     */
    public function approved(): static
    {
        return $this->state(
            fn (): array => [
                'status' => DocumentStatus::Approved,
            ],
        );
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
}
