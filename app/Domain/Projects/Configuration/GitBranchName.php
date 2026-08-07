<?php

declare(strict_types=1);

namespace App\Domain\Projects\Configuration;

/**
 * Validates branch names using the relevant git-check-ref-format rules.
 *
 * The implementation is deterministic and does not invoke the Git executable,
 * inspect a working tree, or contact a remote repository.
 */
final class GitBranchName
{
    /**
     * Determine whether the value can safely represent a Git branch name.
     */
    public static function isValid(string $value): bool
    {
        if (
            $value === ''
            || $value !== trim($value)
            || strlen($value) > 255
            || $value === '@'
            || str_starts_with($value, '-')
        ) {
            return false;
        }

        if (
            str_starts_with($value, '/')
            || str_ends_with($value, '/')
            || str_ends_with($value, '.')
            || str_contains($value, '//')
            || str_contains($value, '..')
            || str_contains($value, '@{')
            || str_contains($value, '\\')
        ) {
            return false;
        }

        /*
         * Git disallows control characters, spaces, DEL, and characters that
         * conflict with revision expressions or refspec syntax.
         */
        if (preg_match('/[\x00-\x20\x7F~^:?*\[]/', $value) !== 0) {
            return false;
        }

        foreach (explode('/', $value) as $component) {
            if (
                $component === ''
                || str_starts_with($component, '.')
                || str_ends_with($component, '.lock')
            ) {
                return false;
            }
        }

        return true;
    }
}
