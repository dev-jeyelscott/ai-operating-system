<?php

declare(strict_types=1);

namespace App\Domain\Codex;

/**
 * Defines the durable cleanup state of a Codex runtime or cleanup resource.
 */
enum CodexCleanupStatus: string
{
    case Required = 'required';
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
