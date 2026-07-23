<?php

declare(strict_types=1);

namespace App\Application\Identity\Exceptions;

use RuntimeException;

/**
 * Represents a safe account-deletion rejection caused by an identity invariant.
 */
final class AccountDeletionBlocked extends RuntimeException
{
    /**
     * Create an error for a user who remains an organization's final owner.
     */
    public static function finalOrganizationOwner(): self
    {
        return new self(
            'Add another owner to every organization you own before deleting your account.',
        );
    }

    /**
     * Create a safe error when the user changed during deletion processing.
     */
    public static function accountChanged(): self
    {
        return new self(
            'Your account changed while deletion was being processed. Please try again.',
        );
    }
}
