<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Application\Documents\StoreProjectDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreProjectDocumentRequest;
use App\Models\Organization;
use App\Models\Project;
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

        $storeProjectDocument->handle(
            organization: $organization,
            project: $project,
            title: (string) $request->validated('title'),
            documentClass: $request->validated('document_class'),
            uploadedFile: $uploadedFile,
        );

        return to_route('organizations.projects.show', [
            'organization' => $organization,
            'project' => $project,
        ])->with('status', 'document-uploaded');
    }
}
