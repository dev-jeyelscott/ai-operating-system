<?php

declare(strict_types=1);

namespace App\Domain\Codex;

/**
 * Defines independently recoverable Codex runtime resources.
 */
enum CodexCleanupResource: string
{
    case Process = 'process';
    case RuntimeCredentials = 'runtime_credentials';
    case TemporaryFiles = 'temporary_files';
    case Workspace = 'workspace';
}
