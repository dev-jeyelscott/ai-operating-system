<?php

declare(strict_types=1);

namespace App\Domain\Codex;

/**
 * Describes durable provider-process session state.
 */
enum ProviderSessionStatus: string
{
    case Active = 'active';

    case Completed = 'completed';

    case Failed = 'failed';

    case Cancelled = 'cancelled';

    case Lost = 'lost';

    /**
     * Determine whether no further provider events should be accepted.
     */
    public function isTerminal(): bool
    {
        return $this !== self::Active;
    }
}
