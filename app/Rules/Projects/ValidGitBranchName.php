<?php

declare(strict_types=1);

namespace App\Rules\Projects;

use App\Domain\Projects\Configuration\GitBranchName;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Applies Git branch-name syntax validation to project configuration input.
 */
final readonly class ValidGitBranchName implements ValidationRule
{
    /**
     * Validate one branch name without inspecting a repository.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(
        string $attribute,
        mixed $value,
        Closure $fail,
    ): void {
        if (
            ! is_string($value)
            || ! GitBranchName::isValid($value)
        ) {
            $fail(
                'The :attribute must be a valid Git branch name.',
            );
        }
    }
}
