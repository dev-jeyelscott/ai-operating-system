<?php

declare(strict_types=1);

namespace App\Application\Documents\Data;

use App\Domain\Documents\DocumentClassification;

final readonly class DocumentAnalysis
{
    /**
     * @param  list<string>  $conflicts
     * @param  list<string>  $gaps
     * @param  list<string>  $flags
     */
    public function __construct(
        public string $summary,
        public DocumentClassification $classification,
        public array $conflicts,
        public array $gaps,
        public array $flags,
    ) {}
}
