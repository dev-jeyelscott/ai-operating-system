<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Support\Security\SensitiveValueRedactor;
use JsonException;
use Throwable;

/**
 * Produces safe infrastructure and persistence content.
 */
final readonly class RedactSensitiveData
{
    /**
     * Inject the application-wide sensitive-value redactor.
     */
    public function __construct(
        private SensitiveValueRedactor $redactor,
    ) {}

    /**
     * Remove payloads, URLs, credentials, headers, and request bodies.
     */
    public function message(string $value): string
    {
        $redacted = preg_replace(
            [
                '/(?:\{.*\}|\[[^\[\]]*\])/s',
                '/\bhttps?:\/\/[^\s]+/i',
                '/\b(?:postgres(?:ql)?|mysql|redis):\/\/[^\s]+/i',
                '/\b(?:authorization|proxy-authorization)\s*[:=]\s*(?:bearer\s+)?[^\s,;]+/i',
                '/\b(?:api[_-]?key|access[_-]?token|refresh[_-]?token|password|secret)\s*[:=]\s*[^\s,;]+/i',
                '/\b(?:request[_ -]?body|body)\s*[:=]\s*(?:\{.*|\[.*|.+)/i',
            ],
            [
                '[redacted-payload]',
                '[redacted-url]',
                '[redacted-connection-string]',
                '[redacted-authorization]',
                '[redacted-secret]',
                '[redacted-request-body]',
            ],
            $value,
        );

        $safeValue = trim(
            is_string($redacted)
                ? $redacted
                : '',
        );

        return $this->redactor->message($safeValue);
    }

    /**
     * Recursively sanitize structured content before persistence.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public function values(array $values): array
    {
        return $this->redactor->redact($values);
    }

    /**
     * Sanitize a list of free-form claims or messages.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    public function strings(array $values): array
    {
        $redacted = [];

        foreach ($values as $value) {
            $redacted[] = $this->message($value);
        }

        return $redacted;
    }

    /**
     * Build the only permitted persisted infrastructure-error representation.
     *
     * @throws JsonException
     */
    public function infrastructureError(
        string $errorCode,
        Throwable $exception,
    ): string {
        return json_encode([
            'error_code' => $errorCode,
            'exception_type' => $exception::class,
            'sanitized_message' => $this->message(
                $exception->getMessage(),
            ),
        ], JSON_THROW_ON_ERROR);
    }
}
