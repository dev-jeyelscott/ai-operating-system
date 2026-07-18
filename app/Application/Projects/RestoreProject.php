<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Application\Audit\RecordAuditEvent;
use App\Application\Projects\Contracts\ProjectRepository;
use App\Application\Shared\Contracts\TransactionManager;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Models\Project;
use InvalidArgumentException;

/**
 * Restores an archived project without changing its workflow lifecycle state.
 */
final readonly class RestoreProject
{
    /**
     * Inject project persistence, audit recording, and transactions.
     */
    public function __construct(
        private ProjectRepository $projects,
        private RecordAuditEvent $audit,
        private TransactionManager $transactions,
    ) {}

    /**
     * Execute the restore command and append its audit event atomically.
     */
    public function handle(
        int $actorUserId,
        int $organizationId,
        int $projectId,
        ?string $correlationId = null,
    ): Project {
        if ($actorUserId < 1) {
            throw new InvalidArgumentException(
                'The actor user identifier must be positive.',
            );
        }

        return $this->transactions->run(
            function () use (
                $actorUserId,
                $organizationId,
                $projectId,
                $correlationId,
            ): Project {
                $project = $this->projects->restore(
                    organizationId: $organizationId,
                    projectId: $projectId,
                );

                $this->audit->record(
                    organizationId: $organizationId,
                    projectId: $project->id,
                    actorType: AuditActorType::User,
                    actorId: (string) $actorUserId,
                    eventType: AuditEventType::ProjectRestored,
                    subjectType: AuditSubjectType::Project,
                    subjectId: (string) $project->id,
                    correlationId: $correlationId,
                    metadata: [
                        'archived_at' => null,
                        'status' => $project->status->value,
                    ],
                );

                return $project;
            },
        );
    }
}
