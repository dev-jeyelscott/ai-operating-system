<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Documents\Exceptions\DocumentProcessingException;
use App\Domain\Documents\DocumentProcessingFailureCode;

/**
 * Enforces the MVP plain-text content boundary.
 */
final class DocumentTextGuard
{
    /**
     * Reject oversized, archived, binary, or invalid UTF-8 content.
     */
    public function assertSafe(string $contents): void
    {
        if (strlen($contents) > $this->maxBytes()) {
            throw DocumentProcessingException::permanent(
                failureCode: DocumentProcessingFailureCode::DocumentTooLarge,
                message: sprintf(
                    'The document exceeds the maximum allowed size of %d MB.',
                    intdiv($this->maxBytes(), 1024 * 1024),
                ),
            );
        }

        if ($this->containsArchiveSignature($contents)) {
            throw DocumentProcessingException::permanent(
                failureCode: DocumentProcessingFailureCode::ArchiveContentDetected,
                message: 'Archive containers are not accepted. Upload a Markdown or plain-text document directly.',
            );
        }

        if (preg_match('//u', $contents) !== 1) {
            throw DocumentProcessingException::permanent(
                failureCode: DocumentProcessingFailureCode::InvalidTextEncoding,
                message: 'The document must contain valid UTF-8 text.',
            );
        }

        if (
            preg_match(
                '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
                $contents,
            ) === 1
        ) {
            throw DocumentProcessingException::permanent(
                failureCode: DocumentProcessingFailureCode::BinaryContentDetected,
                message: 'Binary document content is not accepted. Upload a Markdown or plain-text file.',
            );
        }
    }

    /**
     * Return the configured maximum byte count with a safe fallback.
     */
    public function maxBytes(): int
    {
        $configured = config('documents.upload.max_bytes');

        return is_int($configured) && $configured > 0
            ? $configured
            : 20 * 1024 * 1024;
    }

    /**
     * Detect common archive containers without extracting their contents.
     */
    private function containsArchiveSignature(string $contents): bool
    {
        $signatures = [
            "\x50\x4B\x03\x04",             // ZIP
            "\x50\x4B\x05\x06",             // Empty ZIP
            "\x50\x4B\x07\x08",             // Spanned ZIP
            "\x1F\x8B",                     // GZIP
            "Rar!\x1A\x07\x00",             // RAR 4
            "Rar!\x1A\x07\x01\x00",         // RAR 5
            "7z\xBC\xAF\x27\x1C",           // 7-Zip
            'BZh',                          // BZIP2
        ];

        foreach ($signatures as $signature) {
            if (str_starts_with($contents, $signature)) {
                return true;
            }
        }

        /*
         * POSIX tar archives store "ustar" at byte offset 257. No extraction
         * is attempted because archives are outside the MVP document contract.
         */
        return strlen($contents) >= 262
            && substr($contents, 257, 5) === 'ustar';
    }
}
