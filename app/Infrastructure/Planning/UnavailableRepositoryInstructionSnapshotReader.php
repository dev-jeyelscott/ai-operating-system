<?php

declare(strict_types=1);

namespace App\Infrastructure\Planning;

use App\Application\Planning\Context\Exceptions\PlanningContextAssemblyException;
use App\Application\Planning\Contracts\RepositoryInstructionSnapshotReader;
use App\Application\Planning\Data\RepositoryInstructionSnapshot;
use App\Models\ExecutionAttempt;
use App\Models\ProjectContextSnapshot;

/**
 * Blocks Codex planning until immutable repository checkout is available.
 */
final readonly class UnavailableRepositoryInstructionSnapshotReader implements RepositoryInstructionSnapshotReader
{
    public function read(
        ProjectContextSnapshot $contextSnapshot,
        ExecutionAttempt $attempt,
    ): RepositoryInstructionSnapshot {
        unset($contextSnapshot, $attempt);

        throw PlanningContextAssemblyException::missingRepositorySnapshot();
    }
}
