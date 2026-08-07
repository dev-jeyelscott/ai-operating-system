<?php

declare(strict_types=1);

namespace App\Domain\Documents;

/**
 * Defines stable, user-safe failure codes for document upload and parsing.
 */
enum DocumentProcessingFailureCode: string
{
    case UnsupportedMediaType = 'unsupported_media_type';
    case UnsupportedExtension = 'unsupported_extension';
    case DocumentTooLarge = 'document_too_large';
    case ArchiveContentDetected = 'archive_content_detected';
    case BinaryContentDetected = 'binary_content_detected';
    case InvalidTextEncoding = 'invalid_text_encoding';
    case UploadUnreadable = 'upload_unreadable';
    case StorageReadFailed = 'storage_read_failed';
    case ParseFailed = 'parse_failed';

    /**
     * Determine whether retrying the same immutable file can ever succeed.
     */
    public function isPermanent(): bool
    {
        return match ($this) {
            self::StorageReadFailed,
            self::ParseFailed => false,

            default => true,
        };
    }
}
