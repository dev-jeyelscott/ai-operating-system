<?php

declare(strict_types=1);

namespace App\Application\Planning\Contracts;

use App\Application\Planning\Data\RepositoryInstructionSnapshot;
use App\Models\ExecutionAttempt;
use App\Models\ProjectContextSnapshot;

/**
 * Reads repository guidance only from an immutable, tenant-owned revision.
 */
interface RepositoryInstructionSnapshotReader
{
    public function read(
        ProjectContextSnapshot $contextSnapshot,
        ExecutionAttempt $attempt,
    ): RepositoryInstructionSnapshot;
}
