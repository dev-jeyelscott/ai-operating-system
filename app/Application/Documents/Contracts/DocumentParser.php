<?php

declare(strict_types=1);

namespace App\Application\Documents\Contracts;

use App\Application\Documents\Data\ParsedDocument;
use App\Models\DocumentVersion;

interface DocumentParser
{
    /**
     * Return every media type this parser can process successfully.
     *
     * @return list<string>
     */
    public function supportedMediaTypes(): array;

    /**
     * Determine whether this parser supports the supplied media type.
     */
    public function supports(string $mediaType): bool;

    /**
     * Return the stable parser identifier stored with parsing evidence.
     */
    public function name(): string;

    /**
     * Return the parser implementation version.
     */
    public function version(): string;

    /**
     * Parse an approved document version into normalized text.
     */
    public function parse(DocumentVersion $documentVersion): ParsedDocument;
}
