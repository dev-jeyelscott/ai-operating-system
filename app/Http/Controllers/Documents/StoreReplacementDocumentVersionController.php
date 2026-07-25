<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Application\Audit\Data\AuditContext;
use App\Application\Documents\StoreReplacementDocumentVersion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreReplacementDocumentVersionRequest;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
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

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $requestId = $request->attributes->get('request_id');

        try {
            $storeReplacement->handle(
                organization: $organization,
                project: $project,
                document: $document,
                approvedVersion: $version,
                uploadedFile: $uploadedFile,
                auditContext: AuditContext::user(
                    userId: $user->id,
                    correlationId: is_string($requestId) ? $requestId : null,
                ),
            );
        } catch (LogicException $exception) {
            return to_route(
                'organizations.projects.documents.show',
                [
                    'organization' => $organization,
                    'project' => $project,
                    'document' => $document,
                ],
            )->withErrors([
                'action' => $exception->getMessage(),
            ]);
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
