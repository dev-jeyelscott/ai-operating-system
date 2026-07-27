<?php

declare(strict_types=1);

namespace App\Application\Security;

use JsonException;
use Throwable;

/**
 * Produces safe infrastructure-error messages for durable operational state.
 */
final class RedactSensitiveData
{
    /**
     * Remove credentials, URLs, headers, and request bodies from diagnostic text.
     */
    public function message(string $value): string
    {
        $redacted = preg_replace(
            [
                '/\bhttps?:\/\/[^\s]+/i',
                '/\b(?:postgres(?:ql)?|mysql|redis):\/\/[^\s]+/i',
                '/(?:\{.*\}|\[[^\[\]]*\])/s',
                '/\b(?:authorization|proxy-authorization)\s*[:=]\s*(?:bearer\s+)?[^\s,;]+/i',
                '/\b(?:api[_-]?key|access[_-]?token|refresh[_-]?token|password|secret)\s*[:=]\s*[^\s,;]+/i',
                '/\b(?:request[_ -]?body|body)\s*[:=]\s*(?:\{.*|\[.*|.+)/i',
            ],
            [
                '[redacted-url]',
                '[redacted-connection-string]',
                '[redacted-payload]',
                '[redacted-authorization]',
                '[redacted-secret]',
                '[redacted-request-body]',
            ],
            $value,
        );

        return trim(is_string($redacted) ? $redacted : '');
    }

    /**
     * Build the only persisted outbox error representation.
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
            'sanitized_message' => $this->message($exception->getMessage()),
        ], JSON_THROW_ON_ERROR);
    }
}
