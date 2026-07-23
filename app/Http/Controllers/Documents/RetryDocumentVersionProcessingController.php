<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Application\Documents\RetryDocumentVersionProcessing;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\RetryDocumentVersionProcessingRequest;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use LogicException;

final class RetryDocumentVersionProcessingController extends Controller
{
    public function __invoke(
        RetryDocumentVersionProcessingRequest $request,
        Organization $organization,
        Project $project,
        Document $document,
        DocumentVersion $version,
        RetryDocumentVersionProcessing $retryProcessing,
    ): RedirectResponse {
        abort_unless(
            $document->project_id === $project->id
                && $version->document_id === $document->id,
            404,
        );

        try {
            $retryProcessing->handle($version);
        } catch (LogicException $exception) {
            abort(422, $exception->getMessage());
        }

        return to_route('organizations.projects.documents.show', [
            'organization' => $organization,
            'project' => $project,
            'document' => $document,
        ])->with('status', 'document-processing-retried');
    }
}
