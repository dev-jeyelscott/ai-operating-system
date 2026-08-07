<?php

declare(strict_types=1);

namespace App\Http\Controllers\Approvals;

use App\Application\Codex\Approvals\CodexApprovalBridge;
use App\Domain\Codex\CodexApprovalAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Approvals\DecideCodexApprovalRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records one authorized human decision for a Codex approval request.
 */
final class DecideCodexApprovalController extends Controller
{
    /**
     * Apply the decision and return to the approval inspector.
     */
    public function __invoke(
        DecideCodexApprovalRequest $request,
        Organization $organization,
        Project $project,
        string $codexApprovalRequest,
        CodexApprovalBridge $bridge,
    ): RedirectResponse {
        $user = $request->user();

        abort_unless(
            $user instanceof User,
            Response::HTTP_UNAUTHORIZED,
        );

        $action = CodexApprovalAction::from(
            (string) $request->validated('action'),
        );

        $validatedReason = $request->validated('reason');

        $reason = is_string($validatedReason)
            ? $validatedReason
            : null;

        $result = $bridge->decide(
            organizationId: $organization->id,
            projectId: $project->id,
            codexApprovalRequestId: $codexApprovalRequest,
            actorUserId: $user->id,
            action: $action,
            reason: $reason,
        );

        if (! $result->isSuccessful()) {
            return redirect()
                ->back(Response::HTTP_SEE_OTHER)
                ->withErrors([
                    'approval' => $result->message
                        ?? 'The Codex approval decision could not be applied.',
                ]);
        }

        return redirect()
            ->back(Response::HTTP_SEE_OTHER)
            ->with(
                'status',
                $action === CodexApprovalAction::Defer
                    ? 'Codex approval remains pending.'
                    : 'Codex approval decision recorded.',
            );
    }
}
