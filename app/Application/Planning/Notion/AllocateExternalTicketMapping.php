<?php

declare(strict_types=1);

namespace App\Application\Planning\Notion;

use App\Application\Shared\Contracts\TransactionManager;
use App\Domain\Integrations\IntegrationProvider;
use App\Models\ExternalTicketMapping;
use App\Models\RoadmapTask;

/** Allocates the immutable provider key exactly once for a roadmap task. */
final readonly class AllocateExternalTicketMapping
{
    public function __construct(private TransactionManager $transactions) {}

    public function handle(RoadmapTask $task): ExternalTicketMapping
    {
        return $this->transactions->run(function () use ($task): ExternalTicketMapping {
            $lockedTask = RoadmapTask::query()->with('roadmap')->lockForUpdate()->findOrFail($task->id);
            $mapping = ExternalTicketMapping::query()
                ->where('roadmap_task_id', $lockedTask->id)
                ->where('provider', IntegrationProvider::Notion->value)
                ->lockForUpdate()
                ->first();
            if ($mapping !== null) {
                return $mapping;
            }

            return ExternalTicketMapping::query()->create([
                'roadmap_task_id' => $lockedTask->id,
                'provider' => IntegrationProvider::Notion->value,
                'external_key' => sprintf(
                    'notion:p%d:r%d:t%s',
                    $lockedTask->roadmap->project_id,
                    $lockedTask->roadmap->revision,
                    $lockedTask->stable_id,
                ),
                'state' => 'pending',
            ]);
        });
    }
}
