<?php

declare(strict_types=1);

namespace App\Application\Documents\Contracts;

use App\Application\Documents\Data\DocumentAnalysis;
use App\Models\DocumentVersion;

interface DocumentAnalyzer
{
    public function analyze(DocumentVersion $version, int $seed): DocumentAnalysis;
}
