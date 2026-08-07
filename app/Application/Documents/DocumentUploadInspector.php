<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Documents\Contracts\DocumentParser;
use App\Application\Documents\Data\InspectedDocumentUpload;
use App\Application\Documents\Exceptions\DocumentProcessingException;
use App\Domain\Documents\DocumentProcessingFailureCode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Inspects one upload using server-derived file metadata and content.
 */
final readonly class DocumentUploadInspector
{
    /**
     * Create the shared upload inspection service.
     */
    public function __construct(
        private DocumentParser $documentParser,
        private DocumentTextGuard $textGuard,
    ) {}

    /**
     * Inspect an upload and return trusted metadata for persistence.
     */
    public function inspect(
        UploadedFile $uploadedFile,
    ): InspectedDocumentUpload {
        if (! $uploadedFile->isValid()) {
            throw DocumentProcessingException::permanent(
                failureCode: DocumentProcessingFailureCode::UploadUnreadable,
                message: 'The document upload did not complete successfully.',
            );
        }

        $realPath = $uploadedFile->getRealPath();

        if (! is_string($realPath) || $realPath === '') {
            throw DocumentProcessingException::permanent(
                failureCode: DocumentProcessingFailureCode::UploadUnreadable,
                message: 'The uploaded document could not be inspected.',
            );
        }

        $byteSize = filesize($realPath);

        if ($byteSize === false) {
            throw DocumentProcessingException::permanent(
                failureCode: DocumentProcessingFailureCode::UploadUnreadable,
                message: 'The uploaded document size could not be determined.',
            );
        }

        if ($byteSize === 0) {
            throw DocumentProcessingException::permanent(
                failureCode: DocumentProcessingFailureCode::UploadUnreadable,
                message: 'The document must not be empty.',
            );
        }

        if ($byteSize > $this->maxBytes()) {
            throw DocumentProcessingException::permanent(
                failureCode: DocumentProcessingFailureCode::DocumentTooLarge,
                message: sprintf(
                    'The document may not be larger than %d MB.',
                    intdiv($this->maxBytes(), 1024 * 1024),
                ),
            );
        }

        $extension = strtolower(
            pathinfo(
                $uploadedFile->getClientOriginalName(),
                PATHINFO_EXTENSION,
            ),
        );

        if (! in_array($extension, $this->allowedExtensions(), true)) {
            throw DocumentProcessingException::permanent(
                failureCode: DocumentProcessingFailureCode::UnsupportedExtension,
                message: sprintf(
                    'The document extension is not supported. Supported extensions: %s.',
                    implode(', ', $this->allowedExtensions()),
                ),
            );
        }

        /*
         * getMimeType() uses server-side file inspection. Do not use
         * getClientMimeType(), because that value comes from the request.
         */
        $mediaType = $uploadedFile->getMimeType();

        if (
            ! is_string($mediaType)
            || ! $this->documentParser->supports($mediaType)
        ) {
            throw DocumentProcessingException::permanent(
                failureCode: DocumentProcessingFailureCode::UnsupportedMediaType,
                message: sprintf(
                    'The document format is not supported. Supported media types: %s.',
                    implode(
                        ', ',
                        $this->documentParser->supportedMediaTypes(),
                    ),
                ),
            );
        }

        $contents = file_get_contents($realPath);

        if (! is_string($contents)) {
            throw DocumentProcessingException::permanent(
                failureCode: DocumentProcessingFailureCode::UploadUnreadable,
                message: 'The uploaded document could not be inspected.',
            );
        }

        $this->textGuard->assertSafe($contents);

        $checksum = hash_file('sha256', $realPath);

        if (! is_string($checksum)) {
            throw DocumentProcessingException::permanent(
                failureCode: DocumentProcessingFailureCode::UploadUnreadable,
                message: 'The document checksum could not be calculated.',
            );
        }

        return new InspectedDocumentUpload(
            originalFilename: $this->originalFilename($uploadedFile),
            mediaType: $mediaType,
            byteSize: $byteSize,
            realPath: $realPath,
            checksumSha256: $checksum,
        );
    }

    /**
     * Return the authoritative accepted filename extensions.
     *
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        $configured = config('documents.upload.allowed_extensions');

        if (! is_array($configured)) {
            return ['md', 'txt'];
        }

        $extensions = [];

        foreach ($configured as $extension) {
            if (! is_string($extension)) {
                continue;
            }

            $normalized = strtolower(trim($extension));

            if ($normalized !== '') {
                $extensions[] = $normalized;
            }
        }

        $extensions = array_values(array_unique($extensions));

        return $extensions !== []
            ? $extensions
            : ['md', 'txt'];
    }

    /**
     * Return the maximum accepted byte count.
     */
    public function maxBytes(): int
    {
        return $this->textGuard->maxBytes();
    }

    /**
     * Return the file-rule size limit in whole kilobytes.
     */
    public function maxKilobytes(): int
    {
        return max(1, intdiv($this->maxBytes(), 1024));
    }

    /**
     * Normalize and bound the user-visible original filename.
     */
    private function originalFilename(
        UploadedFile $uploadedFile,
    ): string {
        $filename = trim(
            basename($uploadedFile->getClientOriginalName()),
        );

        return Str::limit(
            $filename !== '' ? $filename : 'document',
            255,
            '',
        );
    }
}
