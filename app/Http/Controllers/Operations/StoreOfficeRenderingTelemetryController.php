<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operations;

use App\Application\Operations\RecordOfficeRenderingTelemetry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\StoreOfficeRenderingTelemetryRequest;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\Response;

/**
 * Accepts bounded renderer-health telemetry for one tenant-scoped project.
 */
final class StoreOfficeRenderingTelemetryController extends Controller
{
    /**
     * Validate and record the presentation-only event batch.
     */
    public function __invoke(
        StoreOfficeRenderingTelemetryRequest $request,
        Organization $organization,
        Project $project,
        RecordOfficeRenderingTelemetry $record,
    ): Response {
        $record->handle(
            organizationId: $organization->id,
            projectId: $project->id,
            actorId: (int) $request->user()->getAuthIdentifier(),
            events: $request->events(),
        );

        return response()->noContent(202);
    }
}
