<?php

declare(strict_types=1);

namespace App\Application\Documents\Contracts;

use App\Application\Documents\Data\ParsedDocument;
use App\Models\DocumentVersion;

interface DocumentParser
{
    public function parse(DocumentVersion $documentVersion): ParsedDocument;
}
