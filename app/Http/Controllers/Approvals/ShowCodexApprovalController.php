<?php

declare(strict_types=1);

namespace App\Http\Controllers\Approvals;

use App\Http\Controllers\Controller;
use App\Models\CodexApprovalRequest;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

/**
 * Resolves one tenant-scoped Codex approval to the existing approval inbox.
 *
 * The generic Approval remains the authoritative human-decision record.
 * This controller only resolves Codex-specific correlation context and sends
 * the user to the existing project approval surface.
 */
final class ShowCodexApprovalController extends Controller
{
    /**
     * Redirect to the owning generic approval and focus it in the inbox.
     */
    public function __invoke(
        Organization $organization,
        Project $project,
        string $codexApprovalRequest,
    ): RedirectResponse {
        $codexRequest = CodexApprovalRequest::query()
            ->where('organization_id', $organization->id)
            ->forProject($project->id)
            ->whereKey($codexApprovalRequest)
            ->firstOrFail();

        $inboxUrl = route(
            'organizations.projects.approvals.index',
            [
                'organization' => $organization,
                'project' => $project,
                'approval' => $codexRequest->approval_id,
            ],
            false,
        );

        return redirect()->to(
            $inboxUrl.'#approval-'.$codexRequest->approval_id,
        );
    }
}
