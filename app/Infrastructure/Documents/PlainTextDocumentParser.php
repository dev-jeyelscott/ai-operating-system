<?php

declare(strict_types=1);

namespace App\Infrastructure\Documents;

use App\Application\Documents\Contracts\DocumentParser;
use App\Application\Documents\Data\ParsedDocument;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class PlainTextDocumentParser implements DocumentParser
{
    /** @var list<string> */
    private const SUPPORTED_MEDIA_TYPES = [
        'text/markdown',
        'text/plain',
    ];

    private const NAME = 'plain-text-mvp';

    private const VERSION = '1.0.0';

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
     * Read a supported Markdown or plain-text document from private storage.
     */
    public function parse(DocumentVersion $documentVersion): ParsedDocument
    {
        if (! $this->supports($documentVersion->media_type)) {
            throw new RuntimeException(sprintf(
                'No parser is registered for media type "%s".',
                $documentVersion->media_type,
            ));
        }

        return new ParsedDocument(
            content: Storage::disk($documentVersion->storage_disk)
                ->get($documentVersion->storage_path),
            parserName: $this->name(),
            parserVersion: $this->version(),
        );
    }
}
