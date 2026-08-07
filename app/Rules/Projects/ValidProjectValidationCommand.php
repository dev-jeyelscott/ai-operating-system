<?php

declare(strict_types=1);

namespace App\Rules\Projects;

use App\Domain\Projects\Configuration\Exceptions\InvalidValidationCommand;
use App\Domain\Projects\Configuration\ValidationCommand;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Adapts the framework-independent ValidationCommand value object to Laravel's
 * request-validation system.
 */
final class ValidProjectValidationCommand implements ValidationRule
{
    /**
     * Validate one submitted project command.
     *
     * Non-string values are left to Laravel's built-in string rule so the
     * client receives the standard validation message.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(
        string $attribute,
        mixed $value,
        Closure $fail,
    ): void {
        if (! is_string($value)) {
            return;
        }

        try {
            ValidationCommand::from($value);
        } catch (InvalidValidationCommand $exception) {
            $fail(sprintf(
                'The %s %s',
                str_replace('_', ' ', $attribute),
                $exception->getMessage(),
            ));
        }
    }
}
