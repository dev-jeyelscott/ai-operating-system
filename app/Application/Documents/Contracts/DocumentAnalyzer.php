<?php

declare(strict_types=1);

namespace App\Application\Documents\Contracts;

use App\Application\Documents\Data\DocumentAnalysis;
use App\Models\DocumentVersion;

interface DocumentAnalyzer
{
    /**
     * Return the stable analyzer identifier persisted as provenance.
     */
    public function name(): string;

    /**
     * Return the analyzer implementation or ruleset version.
     */
    public function version(): string;

    /**
     * Analyze normalized document content deterministically for the given seed.
     */
    public function analyze(
        DocumentVersion $version,
        int $seed,
    ): DocumentAnalysis;
}
