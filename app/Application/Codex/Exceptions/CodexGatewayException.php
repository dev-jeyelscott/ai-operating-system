<?php

declare(strict_types=1);

namespace App\Application\Codex\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Represents one sanitized and classified Codex transport failure.
 *
 * Raw provider payloads, credentials, stderr, or prompts must never be included
 * in this exception.
 */
final class CodexGatewayException extends RuntimeException
{
    public const string STARTUP_FAILED = 'codex.startup_failed';

    public const string VERSION_MISMATCH = 'codex.version_mismatch';

    public const string PROTOCOL_MALFORMED = 'codex.protocol_malformed';

    public const string PROTOCOL_UNKNOWN_MESSAGE = 'codex.protocol_unknown_message';

    public const string DUPLICATE_MESSAGE = 'codex.duplicate_message';

    public const string PROCESS_EXITED = 'codex.process_exited';

    public const string REQUEST_TIMEOUT = 'codex.request_timeout';

    public const string SERVER_OVERLOADED = 'codex.server_overloaded';

    public const string OUTPUT_LIMIT_EXCEEDED = 'codex.output_limit_exceeded';

    public const string WRITE_FAILED = 'codex.stdin_write_failed';

    public const string RPC_ERROR = 'codex.rpc_error';

    /**
     * Create one safe classified gateway failure.
     */
    public function __construct(
        public readonly string $failureCode,
        public readonly bool $retryable,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
        );
    }
}
