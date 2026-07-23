<?php

declare(strict_types=1);

namespace App\Application\Documents\Data;

final readonly class ParsedDocument
{
    public function __construct(public string $content, public string $parserName, public string $parserVersion) {}
}
