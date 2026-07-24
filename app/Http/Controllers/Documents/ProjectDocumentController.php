<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\Project;
use Inertia\Inertia;
use Inertia\Response;
use App\Domain\Documents\DocumentStatus;
use Illuminate\Http\Request;

final class ProjectDocumentController extends Controller
{
    public function index(Organization $organization, Project $project): Response
    {
        $documents = $project->documents()
            ->with('versions')
            ->get()
            ->map(fn (Document $document): array => [
                ...$this->document($document),
                'url' => route('organizations.projects.documents.show', [
                    'organization' => $organization,
                    'project' => $project,
                    'document' => $document,
                ]),
            ]);

        return Inertia::render('documents/index', [
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug],
            'project' => ['name' => $project->name, 'slug' => $project->slug],
            'documents' => $documents,
            'urls' => [
                'project' => route('organizations.projects.show', compact('organization', 'project')),
            ],
        ]);
    }

    public function show(
        Request $request,
        Organization $organization,
        Project $project,
        Document $document,
    ): Response {
        abort_unless(
            $document->project_id === $project->id,
            404,
        );

        $document->load('versions');

        $documentData = $this->document($document);

        $documentData['versions'] = collect(
            $documentData['versions'],
        )
            ->map(
                function (
                    array $version,
                ) use (
                    $organization,
                    $project,
                    $document,
                ): array {
                    $canReceiveReplacement =
                        $version['status']
                        === DocumentStatus::Approved->value;

                    return [
                        ...$version,
                        'replacementUrl' =>
                            $canReceiveReplacement
                                ? route(
                                    'organizations.projects.documents.versions.replacement.store',
                                    [
                                        'organization' => $organization,
                                        'project' => $project,
                                        'document' => $document,
                                        'version' => $version['id'],
                                    ],
                                )
                                : null,
                    ];
                },
            )
            ->all();

        return Inertia::render('documents/show', [
            'organization' => [
                'name' => $organization->name,
                'slug' => $organization->slug,
            ],
            'project' => [
                'name' => $project->name,
                'slug' => $project->slug,
            ],
            'document' => $documentData,
            'permissions' => [
                'replace' => $request
                    ->user()
                    ?->can('update', $project) === true,
            ],
            'urls' => [
                'index' => route(
                    'organizations.projects.documents.index',
                    compact('organization', 'project'),
                ),
            ],
        ]);
    }

    /**
     * @return array{
     *     id: int,
     *     title: string,
     *     versions: array<int, array{
     *         id: int,
     *         version: int,
     *         status: string,
     *         classification: string,
     *         checksum: string,
     *         parserVersion: string|null,
     *         notes: string|null,
     *         flags: list<string>
     *     }>
     * }
     */
    private function document(Document $document): array
    {
        return [
            'id' => $document->id,
            'title' => $document->title,
            'versions' => $document->versions
                ->map(
                    fn (DocumentVersion $version): array => [
                        'id' => (int) $version->id,
                        'version' => (int) $version->version,
                        'status' => $version->status->value,
                        'classification' => $version
                            ->classification
                            ->value,
                        'checksum' => (string) $version
                            ->checksum_sha256,
                        'parserVersion' => $version->parser_version,
                        'analyzerName' => $version->analyzer_name,
                        'analyzerVersion' => $version
                            ->analyzer_version,
                        'analysisSeed' => $version->analysis_seed,
                        'analysisCompletedAt' => $version
                            ->analysis_completed_at
                            ?->toIso8601String(),
                        'notes' => $version->analysis_summary,
                        'flags' => $version->analysis_flags ?? [],
                    ],
                )
                ->values()
                ->all(),
        ];
    }
}
