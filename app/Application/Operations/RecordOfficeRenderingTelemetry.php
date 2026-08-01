<?php

declare(strict_types=1);

namespace App\Application\Operations;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Records privacy-safe 3D presentation telemetry as structured logs.
 *
 * Renderer telemetry is operational observability. It is not workflow truth,
 * audit evidence, billing usage, or verified execution evidence.
 */
final readonly class RecordOfficeRenderingTelemetry
{
    public function __construct(
        private EvaluateOfficeRenderingBudget $budgets,
    ) {}

    /**
     * Write each validated event using an explicit field allowlist.
     *
     * @param  list<array<string, mixed>>  $events
     */
    public function handle(
        int $organizationId,
        int $projectId,
        int $actorId,
        array $events,
    ): void {
        foreach ($events as $event) {
            $budget = $this->budgets->handle($event);

            Log::channel('json')->info(
                'office.renderer.telemetry',
                [
                    'organization_id' => $organizationId,
                    'project_id' => $projectId,
                    'actor_id' => $actorId,
                    'renderer' => Arr::only(
                        $event,
                        [
                            'type',
                            'sessionId',
                            'sequence',
                            'observedAt',
                            'qualityPreset',
                            'reducedMotion',
                            'capabilityStatus',
                            'capabilityReason',
                            'failureReason',
                            'frame',
                        ],
                    ),
                    'performance_budget' => $budget,
                ],
            );

            if ($budget['exceeded'] === true) {
                Log::channel('json')->warning(
                    'office.renderer.performance_budget_exceeded',
                    [
                        'organization_id' => $organizationId,
                        'project_id' => $projectId,
                        'actor_id' => $actorId,
                        'session_id' => $event['sessionId'] ?? null,
                        'sequence' => $event['sequence'] ?? null,
                        'quality_preset' => $budget['preset'],
                        'violations' => $budget['violations'],
                    ],
                );
            }
        }
    }
}
