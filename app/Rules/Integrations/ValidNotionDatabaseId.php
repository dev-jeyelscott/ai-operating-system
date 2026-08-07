<?php

declare(strict_types=1);

namespace App\Rules\Integrations;

use App\Domain\Integrations\NotionDatabaseId;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use InvalidArgumentException;

/**
 * Validates a Notion database UUID or database URL.
 */
final readonly class ValidNotionDatabaseId implements ValidationRule
{
    /**
     * Validate the submitted database reference using the domain value object.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(
        string $attribute,
        mixed $value,
        Closure $fail,
    ): void {
        if (! is_string($value)) {
            $fail(
                'The :attribute must be a valid Notion database identifier.',
            );

            return;
        }

        try {
            NotionDatabaseId::from($value);
        } catch (InvalidArgumentException $exception) {
            $fail($exception->getMessage());
        }
    }
}
