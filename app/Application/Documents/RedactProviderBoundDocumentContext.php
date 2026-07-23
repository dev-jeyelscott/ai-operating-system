<?php

declare(strict_types=1);

namespace App\Application\Documents;

/**
 * Removes credentials and configured sensitive values from outbound context.
 */
final class RedactProviderBoundDocumentContext
{
    /** @var list<string> */
    private const DEFAULT_PATTERNS = [
        '/\bghp_[A-Za-z0-9]{36}\b/',
        '/\bsk-[A-Za-z0-9]{20,}\b/',
        '/\bAKIA[0-9A-Z]{16}\b/',
        '/(?i)\b(password|secret|token|api[_-]?key)\s*[:=]\s*[^\s,;]+/',
    ];

    public function handle(string $content): string
    {
        $patterns = config('document-context-redaction.patterns', self::DEFAULT_PATTERNS);

        if (! is_array($patterns)) {
            $patterns = self::DEFAULT_PATTERNS;
        }

        foreach ($patterns as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }

            $redacted = preg_replace($pattern, '[REDACTED]', $content);

            if (is_string($redacted)) {
                $content = $redacted;
            }
        }

        return $content;
    }
}
