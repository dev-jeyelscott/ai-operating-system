<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Exceptions;

use InvalidArgumentException;

/**
 * Raised when a credential fails the domain security requirements.
 */
final class InvalidIntegrationCredential extends InvalidArgumentException {}
