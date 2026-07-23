<?php

declare(strict_types=1);

namespace App\Rules\Projects;

use App\Domain\Projects\Configuration\GitHubRepositoryUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Applies the schema-v1 GitHub repository URL contract to request input.
 */
final readonly class ValidGitHubRepositoryUrl implements ValidationRule
{
    /**
     * Validate one repository URL without contacting GitHub.
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
            || ! GitHubRepositoryUrl::isValid($value)
        ) {
            $fail(
                'The :attribute must be an HTTPS GitHub repository URL '
                .'such as https://github.com/owner/repository.',
            );
        }
    }
}
