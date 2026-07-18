<?php

declare(strict_types=1);

namespace App\Domain\Projects\Configuration\Exceptions;

use DomainException;

/**
 * Raised when persisted configuration uses a schema version this release
 * cannot safely interpret.
 */
final class UnsupportedProjectConfigurationSchemaVersion extends DomainException
{
    /**
     * Create the exception for an unsupported configuration schema version.
     */
    public static function forVersion(int $version): self
    {
        return new self(
            "Project configuration schema version [{$version}] is not supported.",
        );
    }
}
