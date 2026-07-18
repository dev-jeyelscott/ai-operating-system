<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Application\Audit\RecordAuditEvent;
use App\Application\Projects\Contracts\ProjectRepository;
use App\Application\Shared\Contracts\TransactionManager;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Projects\ProjectType;
use App\Models\Project;
use InvalidArgumentException;

/**
 * Updates mutable project metadata without changing aggregate identity,
 * organization ownership, archive state, or workflow status.
 */
final readonly class UpdateProject
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
     * Apply metadata and append its audit event atomically.
     */
    public function handle(
        int $actorUserId,
        int $organizationId,
        int $projectId,
        string $name,
        ?string $description,
        ProjectType $projectType,
        ?string $correlationId = null,
    ): Project {
        if ($actorUserId < 1) {
            throw new InvalidArgumentException(
                'The actor user identifier must be positive.',
            );
        }

        $normalizedName = $this->normalizeName($name);
        $normalizedDescription = $this->normalizeDescription($description);

        return $this->transactions->run(
            function () use (
                $actorUserId,
                $organizationId,
                $projectId,
                $normalizedName,
                $normalizedDescription,
                $projectType,
                $correlationId,
            ): Project {
                $project = $this->projects->update(
                    organizationId: $organizationId,
                    projectId: $projectId,
                    name: $normalizedName,
                    description: $normalizedDescription,
                    projectType: $projectType,
                );

                $this->audit->record(
                    organizationId: $organizationId,
                    projectId: $project->id,
                    actorType: AuditActorType::User,
                    actorId: (string) $actorUserId,
                    eventType: AuditEventType::ProjectUpdated,
                    subjectType: AuditSubjectType::Project,
                    subjectId: (string) $project->id,
                    correlationId: $correlationId,
                    metadata: [
                        'name' => $project->name,
                        'project_type' => $project->project_type->value,
                        'description_present' => $project->description !== null,
                    ],
                );

                return $project;
            },
        );
    }

    /**
     * Normalize and validate the project name for non-HTTP callers.
     */
    private function normalizeName(string $name): string
    {
        $normalizedName = trim($name);

        if (mb_strlen($normalizedName) < 2) {
            throw new InvalidArgumentException(
                'The project name must contain at least two characters.',
            );
        }

        if (mb_strlen($normalizedName) > 120) {
            throw new InvalidArgumentException(
                'The project name may not exceed 120 characters.',
            );
        }

        return $normalizedName;
    }

    /**
     * Normalize optional project description content.
     */
    private function normalizeDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        $normalizedDescription = trim($description);

        if ($normalizedDescription === '') {
            return null;
        }

        if (mb_strlen($normalizedDescription) > 5000) {
            throw new InvalidArgumentException(
                'The project description may not exceed 5000 characters.',
            );
        }

        return $normalizedDescription;
    }
}
