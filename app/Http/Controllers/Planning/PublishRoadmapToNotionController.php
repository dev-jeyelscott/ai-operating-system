<?php

declare(strict_types=1);

namespace App\Http\Controllers\Planning;

use App\Http\Controllers\Controller;
use App\Http\Requests\Planning\PublishRoadmapToNotionRequest;
use App\Jobs\PublishRoadmapToNotionJob;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Queues one idempotent approved-roadmap publication request.
 */
final class PublishRoadmapToNotionController extends Controller
{
    /**
     * Validate project ownership and queue the publication operation.
     */
    public function __invoke(
        PublishRoadmapToNotionRequest $request,
        Organization $organization,
        Project $project,
        Roadmap $roadmap,
    ): RedirectResponse {
        abort_unless(
            $roadmap->project_id === $project->id,
            404,
        );

        $actor = $request->user();

        abort_unless(
            $actor instanceof User,
            401,
        );

        PublishRoadmapToNotionJob::dispatch(
            actorUserId: $actor->id,
            organizationId: $organization->id,
            roadmapId: $roadmap->id,
            idempotencyKey: (string) $request->validated(
                'idempotency_key',
            ),
            correlationId: (string) $request->attributes->get(
                'request_id',
            ),
        )
            ->onConnection(
                $this->integrationQueueConnection(),
            )
            ->onQueue(
                $this->integrationQueueName(),
            );

        return back()->with(
            'status',
            'notion-publication-queued',
        );
    }

    /**
     * Resolve the configured integration queue connection safely.
     */
    private function integrationQueueConnection(): string
    {
        $connection = config(
            'integration-resilience.notion.queue.connection',
            'redis',
        );

        return is_string($connection)
            && trim($connection) !== ''
                ? trim($connection)
                : 'redis';
    }

    /**
     * Resolve the configured integration queue name safely.
     */
    private function integrationQueueName(): string
    {
        $queue = config(
            'integration-resilience.notion.queue.name',
            'integrations',
        );

        return is_string($queue)
            && trim($queue) !== ''
                ? trim($queue)
                : 'integrations';
    }
}
