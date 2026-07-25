<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the project document inventory and version-detail screens.
 *
 * Every mutation capability is calculated by the server. The frontend must
 * never infer authorization or lifecycle eligibility independently.
 */
final class ProjectDocumentController extends Controller
{
    /**
     * Display the tenant-scoped document inventory and upload capability.
     */
    public function index(
        Request $request,
        Organization $organization,
        Project $project,
    ): Response {
        $canUpdate = $request->user()?->can('update', $project) === true;

        $documents = $project->documents()
            ->with('versions')
            ->get()
            ->map(fn (Document $document): array => [
                ...$this->document($document),
                'url' => route(
                    'organizations.projects.documents.show',
                    [
                        'organization' => $organization,
                        'project' => $project,
                        'document' => $document,
                    ],
                ),
            ])
            ->values();

        return Inertia::render('documents/index', [
            'organization' => [
                'name' => $organization->name,
                'slug' => $organization->slug,
            ],
            'project' => [
                'name' => $project->name,
                'slug' => $project->slug,
            ],
            'documents' => $documents,
            'permissions' => [
                'upload' => $canUpdate,
            ],
            'urls' => [
                'project' => route(
                    'organizations.projects.show',
                    compact('organization', 'project'),
                ),
                'store' => $canUpdate
                    ? route(
                        'organizations.projects.documents.store',
                        compact('organization', 'project'),
                    )
                    : null,
            ],
        ]);
    }

    /**
     * Display one document and every immutable version.
     */
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

        $canUpdate = $request->user()?->can('update', $project) === true;

        $documentData = [
            'id' => (int) $document->id,
            'title' => $document->title,
            'documentClass' => $document->document_class,
            'versions' => $document->versions
                ->sortByDesc('version')
                ->map(
                    fn (DocumentVersion $version): array => $this->version(
                        version: $version,
                        actions: $this->actions(
                            canUpdate: $canUpdate,
                            organization: $organization,
                            project: $project,
                            document: $document,
                            version: $version,
                        ),
                    ),
                )
                ->values()
                ->all(),
        ];

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
            'urls' => [
                'index' => route(
                    'organizations.projects.documents.index',
                    compact('organization', 'project'),
                ),
            ],
        ]);
    }

    /**
     * Serialize a logical document for the inventory screen.
     *
     * @return array<string, mixed>
     */
    private function document(Document $document): array
    {
        return [
            'id' => (int) $document->id,
            'title' => $document->title,
            'documentClass' => $document->document_class,
            'versions' => $document->versions
                ->sortByDesc('version')
                ->map(
                    fn (DocumentVersion $version): array => $this->version(
                        version: $version,
                        actions: $this->emptyActions(),
                    ),
                )
                ->values()
                ->all(),
        ];
    }

    /**
     * Serialize immutable document identity, processing state, and analysis.
     *
     * @param  array{
     *     approve: string|null,
     *     reject: string|null,
     *     replacement: string|null,
     *     retry: string|null
     * }  $actions
     * @return array<string, mixed>
     */
    private function version(
        DocumentVersion $version,
        array $actions,
    ): array {
        $hasFailure = $version->failure_code !== null
            || $version->failure_message !== null;

        return [
            'id' => (int) $version->id,
            'version' => (int) $version->version,
            'originalFilename' => $version->original_filename,
            'mediaType' => $version->media_type,
            'byteSize' => (int) $version->byte_size,
            'status' => $version->status->value,
            'classification' => $version->classification->value,
            'checksum' => $version->checksum_sha256,
            'parserName' => $version->parser_name,
            'parserVersion' => $version->parser_version,
            'analyzerName' => $version->analyzer_name,
            'analyzerVersion' => $version->analyzer_version,
            'analysisSeed' => $version->analysis_seed,
            'analysisCompletedAt' => $version
                ->analysis_completed_at
                ?->toIso8601String(),
            'summary' => $version->analysis_summary,
            'conflicts' => $version->analysis_conflicts ?? [],
            'gaps' => $version->analysis_gaps ?? [],
            'flags' => $version->analysis_flags ?? [],
            'failure' => $hasFailure
                ? [
                    'code' => $version->failure_code,
                    'message' => $version->failure_message,
                ]
                : null,
            'actions' => $actions,
        ];
    }

    /**
     * Produce nullable action URLs from authorization and domain state.
     *
     * URLs function as server-issued capabilities. A missing URL means the
     * action must not be rendered or submitted by the browser.
     *
     * @return array{
     *     approve: string|null,
     *     reject: string|null,
     *     replacement: string|null,
     *     retry: string|null
     * }
     */
    private function actions(
        bool $canUpdate,
        Organization $organization,
        Project $project,
        Document $document,
        DocumentVersion $version,
    ): array {
        if (! $canUpdate) {
            return $this->emptyActions();
        }

        $routeParameters = [
            'organization' => $organization,
            'project' => $project,
            'document' => $document,
            'version' => $version,
        ];

        $canReview = $version->isReadyForReview();

        return [
            'approve' => $canReview
                ? route(
                    'organizations.projects.documents.versions.approve',
                    $routeParameters,
                )
                : null,
            'reject' => $canReview
                ? route(
                    'organizations.projects.documents.versions.reject',
                    $routeParameters,
                )
                : null,
            'replacement' => $version->status->value === 'approved'
                ? route(
                    'organizations.projects.documents.versions.replacement.store',
                    $routeParameters,
                )
                : null,
            'retry' => $version->canRetryProcessing()
                ? route(
                    'organizations.projects.documents.versions.retry',
                    $routeParameters,
                )
                : null,
        ];
    }

    /**
     * Return the stable no-capability action shape.
     *
     * @return array{
     *     approve: null,
     *     reject: null,
     *     replacement: null,
     *     retry: null
     * }
     */
    private function emptyActions(): array
    {
        return [
            'approve' => null,
            'reject' => null,
            'replacement' => null,
            'retry' => null,
        ];
    }
}
