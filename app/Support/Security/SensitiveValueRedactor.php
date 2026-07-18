<?php

declare(strict_types=1);

namespace App\Support\Security;

final class SensitiveValueRedactor
{
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
    ];

    /**
     * Recursively redact values whose keys indicate credentials or secrets.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public function redact(array $values): array
    {
        $redacted = [];

        foreach ($values as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if ($this->isSensitiveKey($normalizedKey)) {
                $redacted[$key] = '[REDACTED]';

                continue;
            }

            $redacted[$key] = is_array($value)
                ? $this->redact($value)
                : $value;
        }

        return $redacted;
    }

    /**
     * Determine whether a context key must never be persisted in logs.
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
