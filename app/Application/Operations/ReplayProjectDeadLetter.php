<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Application\Events\Data\DeadLetterRecord;
use App\Application\Events\DeadLetterManager;
use App\Domain\Events\DeadLetterSource;
use App\Models\Project;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Replays one allowlisted dead letter after proving project ownership.
 */
final readonly class ReplayProjectDeadLetter
{
    /**
     * Inject the existing dead-letter manager.
     */
    public function __construct(
        private DeadLetterManager $deadLetters,
    ) {}

    /**
     * Replay exactly one project-owned dead letter.
     */
    public function handle(
        int $organizationId,
        int $projectId,
        DeadLetterSource $source,
        string $identifier,
        string $actorId,
        string $reason,
    ): DeadLetterRecord {
        Project::query()
            ->forOrganization($organizationId)
            ->whereKey($projectId)
            ->firstOrFail();

        $record = collect(
            $this->deadLetters->inspectForProject(
                organizationId: $organizationId,
                projectId: $projectId,
                source: $source,
                limit: 100,
            ),
        )->first(
            static fn (DeadLetterRecord $record): bool => hash_equals(
                $record->id,
                $identifier,
            ),
        );

        /*
         * A dead letter outside this project, or one that is no longer actively
         * dead-lettered, is deliberately represented as a missing resource.
         */
        if (! $record instanceof DeadLetterRecord) {
            throw new NotFoundHttpException(
                'The requested project dead letter was not found.',
            );
        }

        return $this->deadLetters->replay(
            source: $source,
            identifier: $identifier,
            actorId: $actorId,
            reason: $reason,
        );
    }
}
