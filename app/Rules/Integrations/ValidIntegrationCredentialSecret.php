<?php

declare(strict_types=1);

namespace App\Rules\Integrations;

use App\Domain\Integrations\Exceptions\InvalidIntegrationCredential;
use App\Domain\Integrations\IntegrationCredentialSecret;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Applies the integration credential domain validation to HTTP input.
 */
final readonly class ValidIntegrationCredentialSecret implements ValidationRule
{
    /**
     * Validate one submitted credential without transforming its value.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(
        string $attribute,
        mixed $value,
        Closure $fail,
    ): void {
        /*
         * The preceding "string" rule owns the non-string error.
         */
        if (! is_string($value)) {
            return;
        }

        try {
            IntegrationCredentialSecret::from($value);
        } catch (InvalidIntegrationCredential $exception) {
            $fail($exception->getMessage());
        }
    }
}
