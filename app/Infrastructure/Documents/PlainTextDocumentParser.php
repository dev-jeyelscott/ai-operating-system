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
    public function parse(DocumentVersion $documentVersion): ParsedDocument
    {
        if (! in_array($documentVersion->media_type, ['text/markdown', 'text/plain'], true)) {
            throw new RuntimeException('The uploaded media type is not supported by the MVP parser.');
        }

        return new ParsedDocument(
            Storage::disk($documentVersion->storage_disk)->get($documentVersion->storage_path),
            'plain-text-mvp',
            '1.0.0',
        );
    }
}
