<?php

declare(strict_types=1);

namespace App\Domain\Executions\Exceptions;

use DomainException;

/**
 * Reports a deterministic conflict with the persisted execution lifecycle.
 */
final class ExecutionLifecycleConflict extends DomainException {}
