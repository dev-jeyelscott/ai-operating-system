<?php

declare(strict_types=1);

namespace App\Domain\Projects\Configuration\Exceptions;

use InvalidArgumentException;

/**
 * Indicates that a configured project validation command violates a domain
 * invariant and cannot be stored.
 */
final class InvalidValidationCommand extends InvalidArgumentException {}
