<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Application\Documents\ReviewDocumentVersion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\ReviewDocumentVersionRequest;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use LogicException;

/**
 * Handles explicit document review and replacement commands.
 */
final class ReviewDocumentVersionController extends Controller
{
    public function approve(
        ReviewDocumentVersionRequest $request,
        Organization $organization,
        Project $project,
        Document $document,
        DocumentVersion $version,
        ReviewDocumentVersion $reviewDocumentVersion,
    ): RedirectResponse {
        $this->ensureVersionBelongsToProject($project, $document, $version);

        try {
            $reviewDocumentVersion->approve($document, $version);
        } catch (LogicException $exception) {
            abort(422, $exception->getMessage());
        }

        return $this->redirectToDocument($organization, $project, $document)
            ->with('status', 'document-version-approved');
    }

    public function reject(
        ReviewDocumentVersionRequest $request,
        Organization $organization,
        Project $project,
        Document $document,
        DocumentVersion $version,
        ReviewDocumentVersion $reviewDocumentVersion,
    ): RedirectResponse {
        $this->ensureVersionBelongsToProject($project, $document, $version);

        try {
            $reviewDocumentVersion->reject($document, $version);
        } catch (LogicException $exception) {
            abort(422, $exception->getMessage());
        }

        return $this->redirectToDocument($organization, $project, $document)
            ->with('status', 'document-version-rejected');
    }

    public function supersede(
        ReviewDocumentVersionRequest $request,
        Organization $organization,
        Project $project,
        Document $document,
        DocumentVersion $version,
        ReviewDocumentVersion $reviewDocumentVersion,
    ): RedirectResponse {
        $this->ensureVersionBelongsToProject($project, $document, $version);

        try {
            $reviewDocumentVersion->supersede($document, $version);
        } catch (LogicException $exception) {
            abort(422, $exception->getMessage());
        }

        return $this->redirectToDocument($organization, $project, $document)
            ->with('status', 'document-version-superseded');
    }

    private function ensureVersionBelongsToProject(
        Project $project,
        Document $document,
        DocumentVersion $version,
    ): void {
        abort_unless(
            $document->project_id === $project->id
                && $version->document_id === $document->id,
            404,
        );
    }

    private function redirectToDocument(
        Organization $organization,
        Project $project,
        Document $document,
    ): RedirectResponse {
        return to_route('organizations.projects.documents.show', [
            'organization' => $organization,
            'project' => $project,
            'document' => $document,
        ]);
    }
}
