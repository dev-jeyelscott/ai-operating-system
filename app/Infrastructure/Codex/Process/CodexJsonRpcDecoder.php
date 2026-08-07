<?php

declare(strict_types=1);

namespace App\Infrastructure\Codex\Process;

use App\Application\Codex\Exceptions\CodexGatewayException;
use InvalidArgumentException;
use JsonException;

/**
 * Converts bounded newline-delimited App Server output into validated messages.
 */
final class CodexJsonRpcDecoder
{
    private string $buffer = '';

    /**
     * Maximum bytes permitted for one protocol message.
     *
     * @var positive-int
     */
    private readonly int $maximumMessageBytes;

    /**
     * Maximum nesting depth permitted while decoding JSON.
     *
     * @var positive-int
     */
    private readonly int $maximumJsonDepth;

    /**
     * Create one decoder with explicit message and nesting limits.
     */
    public function __construct(
        int $maximumMessageBytes,
        int $maximumJsonDepth,
    ) {
        if ($maximumMessageBytes < 1) {
            throw new InvalidArgumentException(
                'Codex maximum message size must be positive.',
            );
        }

        if ($maximumJsonDepth < 1) {
            throw new InvalidArgumentException(
                'Codex maximum JSON depth must be positive.',
            );
        }

        $this->maximumMessageBytes = $maximumMessageBytes;
        $this->maximumJsonDepth = $maximumJsonDepth;
    }

    /**
     * Consume one stdout fragment and return every complete JSON message.
     *
     * @return list<array<string, mixed>>
     */
    public function push(
        string $chunk,
    ): array {
        $this->buffer .= $chunk;

        $messages = [];

        while (($position = strpos($this->buffer, "\n")) !== false) {
            if ($position > $this->maximumMessageBytes) {
                throw new CodexGatewayException(
                    CodexGatewayException::OUTPUT_LIMIT_EXCEEDED,
                    false,
                    'Codex App Server produced an oversized protocol message.',
                );
            }

            $line = substr(
                $this->buffer,
                0,
                $position,
            );

            $this->buffer = substr(
                $this->buffer,
                $position + 1,
            );

            $line = rtrim($line, "\r");

            if ($line === '') {
                continue;
            }

            if (strlen($line) > $this->maximumMessageBytes) {
                throw new CodexGatewayException(
                    CodexGatewayException::OUTPUT_LIMIT_EXCEEDED,
                    false,
                    'Codex App Server produced an oversized protocol message.',
                );
            }

            $messages[] = $this->decode($line);
        }

        /*
         * Only the remaining incomplete frame is bounded here. A stdout chunk
         * may legitimately contain multiple complete messages whose combined
         * byte size exceeds the per-message limit.
         */
        if (strlen($this->buffer) > $this->maximumMessageBytes) {
            throw new CodexGatewayException(
                CodexGatewayException::OUTPUT_LIMIT_EXCEEDED,
                false,
                'Codex App Server produced an oversized protocol frame.',
            );
        }

        return $messages;
    }

    /**
     * Decode and validate one complete JSON-RPC-like App Server message.
     *
     * @return array<string, mixed>
     */
    private function decode(
        string $line,
    ): array {
        try {
            $message = json_decode(
                $line,
                true,
                $this->maximumJsonDepth,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new CodexGatewayException(
                CodexGatewayException::PROTOCOL_MALFORMED,
                false,
                'Codex App Server emitted invalid JSON.',
                $exception,
            );
        }

        if (
            ! is_array($message)
            || $message === []
            || array_is_list($message)
        ) {
            throw new CodexGatewayException(
                CodexGatewayException::PROTOCOL_MALFORMED,
                false,
                'Codex App Server message must be a JSON object.',
            );
        }

        $hasMethod = array_key_exists('method', $message);
        $hasId = array_key_exists('id', $message);

        if ($hasMethod) {
            if (
                ! is_string($message['method'])
                || trim($message['method']) === ''
            ) {
                throw new CodexGatewayException(
                    CodexGatewayException::PROTOCOL_MALFORMED,
                    false,
                    'Codex App Server message contains an invalid method.',
                );
            }

            if (
                array_key_exists('params', $message)
                && $message['params'] !== null
                && ! is_array($message['params'])
            ) {
                throw new CodexGatewayException(
                    CodexGatewayException::PROTOCOL_MALFORMED,
                    false,
                    'Codex App Server params must be an object.',
                );
            }

            if ($hasId) {
                $this->assertIdentifier(
                    $message['id'],
                );
            }

            return $message;
        }

        if (! $hasId) {
            throw new CodexGatewayException(
                CodexGatewayException::PROTOCOL_MALFORMED,
                false,
                'Codex App Server message has neither method nor response identifier.',
            );
        }

        $this->assertIdentifier(
            $message['id'],
        );

        $hasResult = array_key_exists(
            'result',
            $message,
        );

        $hasError = array_key_exists(
            'error',
            $message,
        );

        if ($hasResult === $hasError) {
            throw new CodexGatewayException(
                CodexGatewayException::PROTOCOL_MALFORMED,
                false,
                'Codex App Server response must contain exactly one result or error.',
            );
        }

        if (
            $hasError
            && ! is_array($message['error'])
        ) {
            throw new CodexGatewayException(
                CodexGatewayException::PROTOCOL_MALFORMED,
                false,
                'Codex App Server response error must be an object.',
            );
        }

        return $message;
    }

    /**
     * Validate a JSON-RPC request or response identifier.
     */
    private function assertIdentifier(
        mixed $identifier,
    ): void {
        if (
            ! is_int($identifier)
            && ! is_string($identifier)
        ) {
            throw new CodexGatewayException(
                CodexGatewayException::PROTOCOL_MALFORMED,
                false,
                'Codex App Server message identifier is invalid.',
            );
        }
    }
}
