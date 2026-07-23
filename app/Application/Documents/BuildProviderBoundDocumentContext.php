<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Models\DocumentVersion;
use App\Models\ProjectContextSnapshot;

/**
 * Builds the only document payload permitted to leave the application.
 */
final readonly class BuildProviderBoundDocumentContext
{
    public function __construct(
        private RedactProviderBoundDocumentContext $redactor,
    ) {}

    /**
     * @return array<int, array{document_version_id: int, checksum_sha256: string, content: string, safety_flags: list<string>}>
     */
    public function handle(ProjectContextSnapshot $snapshot): array
    {
        $versionIds = array_column(
            $snapshot->approved_document_versions,
            'document_version_id',
        );

        if ($versionIds === []) {
            return [];
        }

        return DocumentVersion::query()
            ->whereIn('id', $versionIds)
            ->whereHas('document', fn ($query) => $query->where('project_id', $snapshot->project_id))
            ->orderBy('document_id')
            ->orderBy('version')
            ->get()
            ->map(fn (DocumentVersion $version): array => [
                'document_version_id' => $version->id,
                'checksum_sha256' => $version->checksum_sha256,
                'content' => $this->redactor->handle((string) $version->parsed_content),
                'safety_flags' => $version->analysis_flags ?? [],
            ])
            ->all();
    }
}
