<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Application\Audit\Data\AuditContext;
use App\Application\Documents\StoreProjectDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreProjectDocumentRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;

final class StoreProjectDocumentController extends Controller
{
    public function __invoke(
        StoreProjectDocumentRequest $request,
        Organization $organization,
        Project $project,
        StoreProjectDocument $storeProjectDocument,
    ): RedirectResponse {
        $uploadedFile = $request->file('document');

        if (! $uploadedFile instanceof UploadedFile) {
            abort(422);
        }

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $requestId = $request->attributes->get('request_id');

        $storeProjectDocument->handle(
            organization: $organization,
            project: $project,
            title: (string) $request->validated('title'),
            documentClass: $request->validated('document_class'),
            uploadedFile: $uploadedFile,
            auditContext: AuditContext::user(
                userId: $user->id,
                correlationId: is_string($requestId) ? $requestId : null,
            ),
        );

        return to_route(
            'organizations.projects.documents.index',
            [
                'organization' => $organization,
                'project' => $project,
            ],
        )->with('status', 'document-uploaded');
    }
}
