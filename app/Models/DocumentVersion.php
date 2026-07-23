<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Documents\DocumentClassification;
use App\Domain\Documents\DocumentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\DocumentVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Immutable file identity and mutable processing metadata for one document revision.
 *
 * @property int $id
 * @property int $document_id
 * @property int $version
 * @property string $original_filename
 * @property string $media_type
 * @property int $byte_size
 * @property string $storage_disk
 * @property string $storage_path
 * @property string $checksum_sha256
 * @property DocumentStatus $status
 * @property DocumentClassification $classification
 * @property string|null $parser_name
 * @property string|null $parser_version
 * @property CarbonImmutable|null $parsing_started_at
 * @property CarbonImmutable|null $parsed_at
 * @property string|null $parsed_content
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property int|null $supersedes_document_version_id
 * @property-read Document $document
 * @property-read DocumentVersion|null $supersedes
 * @property-read Collection<int, DocumentVersion> $supersededBy
 */
#[Fillable([
    'document_id', 'version', 'original_filename', 'media_type', 'byte_size',
    'storage_disk', 'storage_path', 'checksum_sha256', 'status', 'classification',
    'parser_name', 'parser_version', 'parsing_started_at', 'parsed_at', 'parsed_content',
    'failure_code', 'failure_message', 'supersedes_document_version_id',
])]
final class DocumentVersion extends Model
{
    /** @use HasFactory<DocumentVersionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        self::updating(static function (self $documentVersion): void {
            if ($documentVersion->isDirty([
                'document_id', 'version', 'original_filename', 'media_type',
                'byte_size', 'storage_disk', 'storage_path', 'checksum_sha256',
            ])) {
                throw new LogicException('Document version file identity is immutable.');
            }
        });
    }

    /**
     * Start parsing only after a completed, approved malware scan.
     */
    public function beginParsing(
        string $parserName,
        string $parserVersion,
    ): void {
        if ($this->status !== DocumentStatus::ScanApproved) {
            throw new LogicException(
                'A document version cannot be parsed before scan approval.',
            );
        }

        $this->forceFill([
            'status' => DocumentStatus::Parsing,
            'parser_name' => $parserName,
            'parser_version' => $parserVersion,
            'parsing_started_at' => now(),
            'failure_code' => null,
            'failure_message' => null,
        ])->save();
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_document_version_id');
    }

    /** @return HasMany<DocumentVersion, $this> */
    public function supersededBy(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_document_version_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'byte_size' => 'integer',
            'status' => DocumentStatus::class,
            'classification' => DocumentClassification::class,
            'parsing_started_at' => 'immutable_datetime',
            'parsed_at' => 'immutable_datetime',
        ];
    }
}
