<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Application\Documents\StoreReplacementDocumentVersion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreReplacementDocumentVersionRequest;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use LogicException;

/**
 * Handles a real multipart replacement-version submission.
 */
final class StoreReplacementDocumentVersionController extends Controller
{
    public function __invoke(
        StoreReplacementDocumentVersionRequest $request,
        Organization $organization,
        Project $project,
        Document $document,
        DocumentVersion $version,
        StoreReplacementDocumentVersion $storeReplacement,
    ): RedirectResponse {
        abort_unless(
            $document->project_id === $project->id
                && $version->document_id === $document->id,
            404,
        );

        $uploadedFile = $request->file('document');

        if (! $uploadedFile instanceof UploadedFile) {
            abort(422);
        }

        try {
            $storeReplacement->handle(
                organization: $organization,
                project: $project,
                document: $document,
                approvedVersion: $version,
                uploadedFile: $uploadedFile,
            );
        } catch (LogicException $exception) {
            abort(422, $exception->getMessage());
        }

        return to_route(
            'organizations.projects.documents.show',
            [
                'organization' => $organization,
                'project' => $project,
                'document' => $document,
            ],
        )->with(
            'status',
            'document-replacement-uploaded',
        );
    }
}
