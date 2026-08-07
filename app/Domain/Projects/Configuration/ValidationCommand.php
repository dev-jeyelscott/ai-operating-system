<?php

declare(strict_types=1);

namespace App\Domain\Projects\Configuration;

use App\Domain\Projects\Configuration\Exceptions\InvalidValidationCommand;
use Stringable;

/**
 * Represents one normalized project validation command.
 *
 * This value object validates command metadata only. It never executes the
 * configured command or checks whether referenced executables exist.
 */
final readonly class ValidationCommand implements Stringable
{
    /**
     * Bound command size to prevent unexpectedly large configuration payloads.
     */
    public const int MAX_LENGTH = 1000;

    /**
     * Characters treated as harmless presentation-level padding.
     *
     * NUL, vertical tab, form feed, and other control characters are deliberately
     * excluded so validation can reject them instead of silently removing them.
     */
    private const NORMALIZATION_CHARACTERS = " \t\r\n";

    private function __construct(
        private string $value,
    ) {}

    /**
     * Create a validated and normalized project validation command.
     *
     * Spaces, tabs, and line endings surrounding the command are presentation
     * padding. Embedded line endings, NUL bytes, and unsupported control
     * characters are rejected.
     */
    public static function from(string $value): self
    {
        $normalized = trim(
            $value,
            self::NORMALIZATION_CHARACTERS,
        );

        if ($normalized === '') {
            throw new InvalidValidationCommand(
                'The validation command must contain at least one non-whitespace character.',
            );
        }

        if (mb_strlen($normalized) > self::MAX_LENGTH) {
            throw new InvalidValidationCommand(
                sprintf(
                    'The validation command must not exceed %d characters.',
                    self::MAX_LENGTH,
                ),
            );
        }

        if (preg_match('/[\r\n]/', $normalized) === 1) {
            throw new InvalidValidationCommand(
                'The validation command must be a single line.',
            );
        }

        if (
            preg_match(
                '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
                $normalized,
            ) === 1
        ) {
            throw new InvalidValidationCommand(
                'The validation command contains an unsupported control character.',
            );
        }

        return new self($normalized);
    }

    /**
     * Return the normalized command for persistence or transport.
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Return the normalized command when used as a string.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
