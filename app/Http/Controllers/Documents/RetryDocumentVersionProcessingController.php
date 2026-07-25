<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Application\Audit\Data\AuditContext;
use App\Application\Documents\RetryDocumentVersionProcessing;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\RetryDocumentVersionProcessingRequest;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
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

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $requestId = $request->attributes->get('request_id');

        try {
            $retryProcessing->handle(
                version: $version,
                auditContext: AuditContext::user(
                    userId: $user->id,
                    correlationId: is_string($requestId) ? $requestId : null,
                ),
            );
        } catch (LogicException $exception) {
            throw ValidationException::withMessages([
                'action' => $exception->getMessage(),
            ]);
        }

        return to_route('organizations.projects.documents.show', [
            'organization' => $organization,
            'project' => $project,
            'document' => $document,
        ])->with('status', 'document-processing-retried');
    }
}
