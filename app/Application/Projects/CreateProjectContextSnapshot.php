<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Domain\Documents\DocumentStatus;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ProjectConfigurationVersion;
use App\Models\ProjectContextSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Freezes the exact configuration revision and approved document identities.
 */
final class CreateProjectContextSnapshot
{
    public function handle(int $organizationId, int $projectId): ProjectContextSnapshot
    {
        return DB::transaction(function () use ($organizationId, $projectId): ProjectContextSnapshot {
            $project = Project::query()
                ->forOrganization($organizationId)
                ->whereKey($projectId)
                ->lockForUpdate()
                ->firstOrFail();

            $configurationVersion = ProjectConfigurationVersion::query()
                ->where('project_id', $project->id)
                ->orderByDesc('revision')
                ->lockForUpdate()
                ->firstOrFail();

            $approvedDocumentVersions = DocumentVersion::query()
                ->where('status', DocumentStatus::Approved->value)
                ->whereHas('document', fn ($query) => $query->where('project_id', $project->id))
                ->orderBy('document_id')
                ->orderBy('version')
                ->get()
                ->map(fn (DocumentVersion $version): array => [
                    'document_id' => $version->document_id,
                    'document_version_id' => $version->id,
                    'version' => $version->version,
                    'checksum_sha256' => $version->checksum_sha256,
                ])
                ->all();

            return ProjectContextSnapshot::query()->create([
                'project_id' => $project->id,
                'project_configuration_version_id' => $configurationVersion->id,
                'configuration_revision' => $configurationVersion->revision,
                'approved_document_versions' => $approvedDocumentVersions,
            ]);
        });
    }
}
