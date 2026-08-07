<?php

declare(strict_types=1);

namespace App\Http\Controllers\QualityAssurance;

use App\Application\QualityAssurance\Commands\DecideSimulatedMergeCommand;
use App\Application\Shared\Commands\CommandBus;
use App\Domain\QualityAssurance\MergeDecisionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\QualityAssurance\SubmitSimulatedMergeDecisionRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\QaAssessment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

/**
 * Dispatches one authorized simulated merge decision through the command bus.
 */
final class SubmitSimulatedMergeDecisionController extends Controller
{
    /**
     * Validate, dispatch, and redirect to the authoritative report state.
     */
    public function __invoke(
        SubmitSimulatedMergeDecisionRequest $request,
        Organization $organization,
        Project $project,
        QaAssessment $assessment,
        CommandBus $commands,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $validated = $request->validated();
        $action = MergeDecisionAction::from((string) $validated['action']);
        $result = $commands->dispatch(new DecideSimulatedMergeCommand(
            organizationId: $organization->id,
            projectId: $project->id,
            qaAssessmentId: $assessment->id,
            actorUserId: $actor->id,
            action: $action,
            expectedAssessmentFingerprint: (string) $validated[
                'expected_assessment_fingerprint'
            ],
            requestIdempotencyKey: (string) $validated['idempotency_key'],
            correlationId: (string) Str::ulid(),
            reason: isset($validated['reason'])
                ? (string) $validated['reason']
                : null,
        ));

        if (! $result->isSuccessful()) {
            return back()->withErrors([
                'decision' => $result->message
                    ?? 'The simulated merge decision could not be applied.',
            ]);
        }

        return back()->with('status', match ($action) {
            MergeDecisionAction::Approve => 'simulated-merge-approved',
            MergeDecisionAction::RequestChanges => 'simulated-merge-changes-requested',
            MergeDecisionAction::Escalate => 'simulated-merge-escalated',
            MergeDecisionAction::Defer => 'simulated-merge-deferred',
        });
    }
}
