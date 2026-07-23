<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Documents\Contracts\DocumentAnalyzer;
use App\Domain\Documents\DocumentStatus;
use App\Models\DocumentVersion;

final readonly class AnalyzeDocumentVersion
{
    public function __construct(private DocumentAnalyzer $analyzer) {}

    public function handle(int $id, int $seed): void
    {
        $version = DocumentVersion::query()->findOrFail($id);
        if ($version->status !== DocumentStatus::Parsed) {
            return;
        }

        $analysis = $this->analyzer->analyze($version, $seed);

        $version->forceFill([
            'classification' => $analysis->classification,
            'analysis_summary' => $analysis->summary,
            'analysis_conflicts' => $analysis->conflicts,
            'analysis_gaps' => $analysis->gaps,
            'analysis_flags' => $analysis->flags,
        ])->save();
    }
}
