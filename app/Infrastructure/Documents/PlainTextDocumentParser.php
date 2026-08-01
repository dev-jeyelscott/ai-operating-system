<?php

declare(strict_types=1);

namespace App\Infrastructure\Documents;

use App\Application\Documents\Contracts\DocumentParser;
use App\Application\Documents\Data\ParsedDocument;
use App\Application\Documents\DocumentTextGuard;
use App\Application\Documents\Exceptions\DocumentProcessingException;
use App\Domain\Documents\DocumentProcessingFailureCode;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Parses the text-only document formats supported by the MVP.
 */
final readonly class PlainTextDocumentParser implements DocumentParser
{
    /** @var list<string> */
    private const SUPPORTED_MEDIA_TYPES = [
        'text/markdown',
        'text/plain',
    ];

    private const NAME = 'plain-text-mvp';

    private const VERSION = '1.1.0';

    /**
     * Create the parser with the shared text-safety guard.
     */
    public function __construct(
        private DocumentTextGuard $textGuard,
    ) {}

    /**
     * Return the media types handled by the MVP text parser.
     *
     * @return list<string>
     */
    public function supportedMediaTypes(): array
    {
        return self::SUPPORTED_MEDIA_TYPES;
    }

    /**
     * Determine whether the supplied media type can be parsed.
     */
    public function supports(string $mediaType): bool
    {
        return in_array(
            strtolower(trim($mediaType)),
            self::SUPPORTED_MEDIA_TYPES,
            true,
        );
    }

    /**
     * Return the stable parser name used in document evidence.
     */
    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Return the active parser implementation version.
     */
    public function version(): string
    {
        return self::VERSION;
    }

    /**
     * Read and validate a supported text document from private storage.
     */
    public function parse(DocumentVersion $documentVersion): ParsedDocument
    {
        if (! $this->supports($documentVersion->media_type)) {
            throw DocumentProcessingException::permanent(
                failureCode: DocumentProcessingFailureCode::UnsupportedMediaType,
                message: sprintf(
                    'No parser is registered for media type "%s".',
                    $documentVersion->media_type,
                ),
            );
        }

        if ($documentVersion->byte_size > $this->textGuard->maxBytes()) {
            throw DocumentProcessingException::permanent(
                failureCode: DocumentProcessingFailureCode::DocumentTooLarge,
                message: 'The stored document exceeds the current processing size limit.',
            );
        }

        try {
            $contents = Storage::disk($documentVersion->storage_disk)
                ->get($documentVersion->storage_path);
        } catch (Throwable $exception) {
            throw DocumentProcessingException::retryable(
                failureCode: DocumentProcessingFailureCode::StorageReadFailed,
                message: 'The stored document could not be read.',
                previous: $exception,
            );
        }

        $this->textGuard->assertSafe($contents);

        return new ParsedDocument(
            content: $contents,
            parserName: $this->name(),
            parserVersion: $this->version(),
        );
    }
}
