<?php

declare(strict_types=1);

namespace App\Support\Security;

use BackedEnum;
use DateTimeInterface;
use LogicException;
use Stringable;
use Throwable;
use UnitEnum;

final class SensitiveValueRedactor
{
    public const REDACTED = '[REDACTED]';

    public const REDACTION_FAILED = '[REDACTION_FAILED]';

    private const SENSITIVE_KEY_FRAGMENTS = [
        'authorization',
        'cookie',
        'credential',
        'password',
        'private_key',
        'secret',
        'token',
        'api_key',
        'access_key',
        'session',
        'csrf',
        'signature',
        'webhook_secret',
        'webhook_url',
    ];

    /**
     * Cache validated patterns after their first successful resolution.
     *
     * @var list<array{id: string, expression: string}>|null
     */
    private ?array $validatedPatterns = null;

    /**
     * Record a failed corpus resolution so subsequent calls remain fail-closed.
     */
    private bool $redactionUnavailable = false;

    /**
     * Inject the authoritative provider-bound redaction corpus.
     */
    public function __construct(
        private readonly ProviderBoundRedactionPatterns $patterns,
    ) {}

    /**
     * Recursively redact both sensitive keys and embedded sensitive values.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public function redact(array $values): array
    {
        return $this->redactArray(
            values: $values,
            redactSensitiveKeys: true,
        );
    }

    /**
     * Redact embedded values while retaining field names.
     *
     * This is intended for validation responses, where a field named
     * "password" must retain its safe validation message.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public function redactValues(array $values): array
    {
        return $this->redactArray(
            values: $values,
            redactSensitiveKeys: false,
        );
    }

    /**
     * Redact secrets embedded inside a plain message.
     */
    public function message(string $value): string
    {
        return $this->redactString($value);
    }

    /**
     * Determine whether a value would change during secure redaction.
     */
    public function containsSensitiveData(mixed $value): bool
    {
        return $this->redactValue(
            value: $value,
            redactSensitiveKeys: true,
        ) !== $value;
    }

    /**
     * Reject content that has not been sanitized before persistence.
     */
    public function assertClean(
        mixed $value,
        string $resourceName,
    ): void {
        if (! $this->containsSensitiveData($value)) {
            return;
        }

        throw new LogicException(sprintf(
            'Sensitive data was detected in %s; persistence was blocked.',
            $resourceName,
        ));
    }

    /**
     * Recursively redact an array.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function redactArray(
        array $values,
        bool $redactSensitiveKeys,
    ): array {
        $redacted = [];

        foreach ($values as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if (
                $redactSensitiveKeys
                && $this->isSensitiveKey($normalizedKey)
            ) {
                $redacted[$key] = self::REDACTED;

                continue;
            }

            $redacted[$key] = $this->redactValue(
                value: $value,
                redactSensitiveKeys: $redactSensitiveKeys,
            );
        }

        return $redacted;
    }

    /**
     * Redact one scalar, array, exception, enum, or stringable value.
     */
    private function redactValue(
        mixed $value,
        bool $redactSensitiveKeys,
    ): mixed {
        if (is_array($value)) {
            return $this->redactArray(
                values: $value,
                redactSensitiveKeys: $redactSensitiveKeys,
            );
        }

        if (is_string($value)) {
            return $this->redactString($value);
        }

        if ($value instanceof Throwable) {
            return [
                'exception_type' => $value::class,
                'message' => $this->redactString(
                    $value->getMessage(),
                ),
                'code' => $value->getCode(),
            ];
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($value instanceof Stringable) {
            return $this->redactString((string) $value);
        }

        if (is_object($value)) {
            return sprintf('[object:%s]', $value::class);
        }

        if (is_resource($value)) {
            return sprintf(
                '[resource:%s]',
                get_resource_type($value),
            );
        }

        return $value;
    }

    /**
     * Apply every validated redaction pattern to one string.
     */
    private function redactString(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $patterns = $this->validatedPatternCorpus();

        if ($patterns === null) {
            return self::REDACTION_FAILED;
        }

        $redacted = $value;

        foreach ($patterns as $pattern) {
            $result = null;
            $pcreErrorCode = PREG_INTERNAL_ERROR;

            set_error_handler(
                static fn (
                    int $_severity,
                    string $_message,
                ): bool => true,
            );

            try {
                $result = preg_replace(
                    pattern: $pattern['expression'],
                    replacement: self::REDACTED,
                    subject: $redacted,
                );

                $pcreErrorCode = preg_last_error();
            } finally {
                restore_error_handler();
            }

            if (
                ! is_string($result)
                || $pcreErrorCode !== PREG_NO_ERROR
            ) {
                $this->redactionUnavailable = true;

                return self::REDACTION_FAILED;
            }

            $redacted = $result;
        }

        return $redacted;
    }

    /**
     * Resolve and cache the complete validated corpus.
     *
     * @return list<array{id: string, expression: string}>|null
     */
    private function validatedPatternCorpus(): ?array
    {
        if ($this->redactionUnavailable) {
            return null;
        }

        if ($this->validatedPatterns !== null) {
            return $this->validatedPatterns;
        }

        try {
            $this->validatedPatterns = $this->patterns->all();
        } catch (Throwable) {
            $this->redactionUnavailable = true;

            return null;
        }

        return $this->validatedPatterns;
    }

    /**
     * Determine whether a context key represents sensitive content.
     */
    private function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
