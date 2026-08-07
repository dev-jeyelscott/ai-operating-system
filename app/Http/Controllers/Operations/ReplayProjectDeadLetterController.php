<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operations;

use App\Application\Operations\ReplayProjectDeadLetter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\ReplayProjectDeadLetterRequest;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Handles one authorized project dead-letter replay.
 */
final class ReplayProjectDeadLetterController extends Controller
{
    /**
     * Replay the selected allowlisted dead letter.
     */
    public function __invoke(
        ReplayProjectDeadLetterRequest $request,
        Organization $organization,
        Project $project,
        ReplayProjectDeadLetter $replay,
    ): RedirectResponse {
        try {
            $record = $replay->handle(
                organizationId: $organization->id,
                projectId: $project->id,
                source: $request->source(),
                identifier: $request->identifier(),
                actorId: (string) $request->user()->getAuthIdentifier(),
                reason: $request->reason(),
            );
        } catch (HttpExceptionInterface $exception) {
            /*
             * Preserve fail-closed HTTP responses such as the 404 returned for
             * foreign, missing, or already-replayed dead-letter identifiers.
             */
            throw $exception;
        } catch (InvalidArgumentException|RuntimeException $exception) {
            /*
             * Recoverable operational failures return to the recovery center
             * without exposing raw dead-letter payloads or exception details.
             */
            return back()->withErrors([
                'replay' => $exception->getMessage(),
            ]);
        }

        return back()->with(
            'success',
            sprintf(
                'Dead letter %s was queued for safe replay.',
                $record->eventId,
            ),
        );
    }
}
