<?php

declare(strict_types=1);

namespace App\Infrastructure\Codex\Process;

use App\Application\Codex\Contracts\CodexProcessSession;
use App\Application\Codex\Data\CodexGatewayEvent;
use App\Application\Codex\Data\CodexGatewayInitialization;
use App\Application\Codex\Data\CodexProcessContext;
use App\Application\Codex\Data\CodexProcessStatus;
use App\Application\Codex\Data\CodexThreadReference;
use App\Application\Codex\Data\CodexTurnReference;
use App\Application\Codex\Exceptions\CodexGatewayException;
use App\Application\Security\RedactSensitiveData;
use Carbon\CarbonImmutable;
use JsonException;
use SplQueue;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Owns the bidirectional JSONL protocol for one App Server process.
 */
final class SymfonyCodexProcessSession implements CodexProcessSession
{
    private bool $initialized = false;

    private bool $shuttingDown = false;

    private int $nextRequestId = 1;

    private int $sequence = 0;

    private int $eventsSeen = 0;

    private int $stderrBytesSeen = 0;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $responses = [];

    /**
     * @var array<string, true>
     */
    private array $seenResponseIds = [];

    /**
     * @var array<string, true>
     */
    private array $seenServerRequestIds = [];

    /**
     * @var array<string, string>
     */
    private array $resolvedApprovalRequests = [];

    /**
     * @var array<string, true>
     */
    private array $interruptedTurns = [];

    /**
     * @var SplQueue<CodexGatewayEvent>
     */
    private SplQueue $events;

    /**
     * Create one stateful process-session adapter.
     */
    public function __construct(
        private readonly CodexProcessContext $context,
        private readonly CodexAppServerSettings $settings,
        private readonly Process $process,
        private readonly InputStream $input,
        private readonly CodexJsonRpcDecoder $decoder,
        private readonly RedactSensitiveData $redactor,
    ) {
        $this->events = new SplQueue;
    }

    /**
     * Complete the mandatory initialize / initialized handshake exactly once.
     */
    public function initialize(): CodexGatewayInitialization
    {
        if ($this->initialized) {
            throw new CodexGatewayException(
                CodexGatewayException::PROTOCOL_MALFORMED,
                false,
                'Codex App Server connection was already initialized.',
            );
        }

        $result = $this->request(
            method: 'initialize',
            params: [
                'clientInfo' => [
                    'name' => 'ai-operating-system',
                    'title' => 'AI Operating System',
                    'version' => '1',
                ],
                'capabilities' => [
                    'experimentalApi' => false,
                ],
            ],
            timeoutSeconds: $this->settings
                ->startupTimeoutSeconds,
        );

        $userAgent = $this->requiredResultString(
            $result,
            'userAgent',
        );

        $codexHome = $this->requiredResultString(
            $result,
            'codexHome',
        );

        $platformFamily = $this->requiredResultString(
            $result,
            'platformFamily',
        );

        $platformOs = $this->requiredResultString(
            $result,
            'platformOs',
        );

        if (
            realpath($codexHome) === false
            || realpath($codexHome)
            !== realpath($this->context->codexHomePath)
        ) {
            throw new CodexGatewayException(
                CodexGatewayException::VERSION_MISMATCH,
                false,
                'Codex App Server initialized with an unexpected Codex home.',
            );
        }

        $this->notification(
            method: 'initialized',
        );

        $this->initialized = true;

        return new CodexGatewayInitialization(
            userAgent: $userAgent,
            codexHome: $codexHome,
            platformFamily: $platformFamily,
            platformOs: $platformOs,
            binaryVersion: $this->settings
                ->expectedBinaryVersion,
            protocolVersion: $this->settings
                ->protocolVersion,
            schemaFingerprint: $this->settings
                ->schemaFingerprint,
        );
    }

    /**
     * Start a provider thread without permitting caller-controlled cwd, model,
     * or sandbox escalation.
     */
    public function startThread(
        string $approvalPolicy,
    ): CodexThreadReference {
        $this->assertInitialized();

        if (
            trim($approvalPolicy) === ''
            || strlen($approvalPolicy) > 100
        ) {
            throw new CodexGatewayException(
                CodexGatewayException::PROTOCOL_MALFORMED,
                false,
                'Codex approval policy is invalid.',
            );
        }

        $result = $this->request(
            method: 'thread/start',
            params: [
                'model' => $this->context->modelIdentifier,
                'cwd' => $this->context->workspacePath,
                'approvalPolicy' => $approvalPolicy,
                'sandbox' => match ($this->context->sandboxProfile) {
                    'read-only' => 'readOnly',
                    'workspace-write' => 'workspaceWrite',
                    default => throw new CodexGatewayException(
                        CodexGatewayException::PROTOCOL_MALFORMED,
                        false,
                        'Unsupported Codex sandbox profile.',
                    ),
                },
            ],
        );

        $thread = $result['thread'] ?? null;

        if (! is_array($thread)) {
            throw new CodexGatewayException(
                CodexGatewayException::PROTOCOL_MALFORMED,
                false,
                'Codex thread/start result is malformed.',
            );
        }

        return new CodexThreadReference(
            id: $this->requiredResultString(
                $thread,
                'id',
            ),
        );
    }

    /**
     * Start one turn using only caller-supplied structured input.
     *
     * The concrete process boundary accepts a broader array shape than the
     * application contract so malformed runtime input can still be rejected
     * before it reaches the provider process.
     *
     * @param  array<int, mixed>  $input
     */
    public function startTurn(
        string $threadId,
        array $input,
    ): CodexTurnReference {
        $this->assertInitialized();

        if (
            trim($threadId) === ''
            || ! array_is_list($input)
            || $input === []
        ) {
            throw new CodexGatewayException(
                CodexGatewayException::PROTOCOL_MALFORMED,
                false,
                'Codex turn request is invalid.',
            );
        }

        foreach ($input as $item) {
            if (
                ! is_array($item)
                || ! isset($item['type'])
                || ! is_string($item['type'])
            ) {
                throw new CodexGatewayException(
                    CodexGatewayException::PROTOCOL_MALFORMED,
                    false,
                    'Codex turn input item is invalid.',
                );
            }
        }

        $result = $this->request(
            method: 'turn/start',
            params: [
                'threadId' => $threadId,
                'input' => $input,
            ],
        );

        $turn = $result['turn'] ?? null;

        if (! is_array($turn)) {
            throw new CodexGatewayException(
                CodexGatewayException::PROTOCOL_MALFORMED,
                false,
                'Codex turn/start result is malformed.',
            );
        }

        return new CodexTurnReference(
            id: $this->requiredResultString(
                $turn,
                'id',
            ),
            status: $this->requiredResultString(
                $turn,
                'status',
            ),
        );
    }

    /**
     * Send one idempotent response to a provider-originated approval request.
     */
    public function respondToApproval(
        int|string $requestId,
        string $decision,
    ): void {
        $this->assertInitialized();

        $allowed = [
            'accept',
            'acceptForSession',
            'acceptWithExecpolicyAmendment',
            'applyNetworkPolicyAmendment',
            'decline',
            'cancel',
        ];

        if (! in_array($decision, $allowed, true)) {
            throw new CodexGatewayException(
                CodexGatewayException::PROTOCOL_MALFORMED,
                false,
                'Codex approval decision is unsupported.',
            );
        }

        $key = $this->identifierKey(
            $requestId,
        );

        if (isset($this->resolvedApprovalRequests[$key])) {
            if (
                $this->resolvedApprovalRequests[$key]
                !== $decision
            ) {
                throw new CodexGatewayException(
                    CodexGatewayException::DUPLICATE_MESSAGE,
                    false,
                    'Codex approval request received conflicting decisions.',
                );
            }

            return;
        }

        $this->write([
            'id' => $requestId,
            'result' => [
                'decision' => $decision,
            ],
        ]);

        $this->resolvedApprovalRequests[$key] = $decision;
    }

    /**
     * Request idempotent interruption of one active turn.
     */
    public function interruptTurn(
        string $threadId,
        string $turnId,
    ): void {
        if (! $this->process->isRunning()) {
            return;
        }

        $this->assertInitialized();

        $key = $threadId.':'.$turnId;

        if (isset($this->interruptedTurns[$key])) {
            return;
        }

        $this->request(
            method: 'turn/interrupt',
            params: [
                'threadId' => $threadId,
                'turnId' => $turnId,
            ],
        );

        $this->interruptedTurns[$key] = true;
    }

    /**
     * Poll for one normalized provider notification or server request.
     */
    public function nextEvent(
        int $timeoutMilliseconds = 250,
    ): ?CodexGatewayEvent {
        if ($timeoutMilliseconds < 0) {
            throw new CodexGatewayException(
                CodexGatewayException::PROTOCOL_MALFORMED,
                false,
                'Codex event polling timeout is invalid.',
            );
        }

        $deadline = microtime(true)
            + ($timeoutMilliseconds / 1000);

        do {
            $this->pump();

            if (! $this->events->isEmpty()) {
                return $this->events->dequeue();
            }

            if (
                ! $this->process->isRunning()
                && ! $this->shuttingDown
            ) {
                throw new CodexGatewayException(
                    CodexGatewayException::PROCESS_EXITED,
                    true,
                    'Codex App Server exited before a terminal application result.',
                );
            }

            if ($timeoutMilliseconds === 0) {
                break;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    /**
     * Return a transient liveness snapshot.
     */
    public function status(): CodexProcessStatus
    {
        return new CodexProcessStatus(
            running: $this->process->isRunning(),
            initialized: $this->initialized,
            processId: $this->process->getPid(),
            exitCode: $this->process->getExitCode(),
            eventsSeen: $this->eventsSeen,
            stderrBytesSeen: $this->stderrBytesSeen,
        );
    }

    /**
     * Close the process idempotently and force-stop after the configured grace
     * period when necessary.
     */
    public function shutdown(): void
    {
        if ($this->shuttingDown) {
            return;
        }

        $this->shuttingDown = true;

        try {
            $this->input->close();
        } catch (Throwable) {
            // Shutdown remains best-effort and idempotent.
        }

        $deadline = microtime(true)
            + $this->settings->shutdownGraceSeconds;

        while (
            $this->process->isRunning()
            && microtime(true) < $deadline
        ) {
            usleep(25_000);
        }

        if ($this->process->isRunning()) {
            $this->process->stop(
                timeout: 0,
                signal: 9,
            );
        }
    }

    /**
     * Send one JSON-RPC request and wait for its correlated response.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        array $params = [],
        ?int $timeoutSeconds = null,
    ): array {
        if (! $this->process->isRunning()) {
            throw new CodexGatewayException(
                CodexGatewayException::PROCESS_EXITED,
                true,
                'Codex App Server is not running.',
            );
        }

        $requestId = $this->nextRequestId++;

        $this->write([
            'id' => $requestId,
            'method' => $method,
            'params' => $params,
        ]);

        $key = $this->identifierKey(
            $requestId,
        );

        $deadline = microtime(true)
            + (
                $timeoutSeconds
                ?? $this->settings->requestTimeoutSeconds
            );

        while (microtime(true) < $deadline) {
            $this->pump();

            if (isset($this->responses[$key])) {
                $response = $this->responses[$key];
                unset($this->responses[$key]);

                return $this->result(
                    $response,
                );
            }

            if (! $this->process->isRunning()) {
                throw new CodexGatewayException(
                    CodexGatewayException::PROCESS_EXITED,
                    true,
                    'Codex App Server exited before responding.',
                );
            }

            usleep(10_000);
        }

        throw new CodexGatewayException(
            CodexGatewayException::REQUEST_TIMEOUT,
            true,
            'Codex App Server request exceeded its configured timeout.',
        );
    }

    /**
     * Send one client notification without a request identifier.
     *
     * @param  array<string, mixed>  $params
     */
    private function notification(
        string $method,
        array $params = [],
    ): void {
        $message = [
            'method' => $method,
        ];

        if ($params !== []) {
            $message['params'] = $params;
        }

        $this->write($message);
    }

    /**
     * Encode and write one bounded JSONL message to stdin.
     *
     * @param  array<string, mixed>  $message
     */
    private function write(
        array $message,
    ): void {
        try {
            $encoded = json_encode(
                $message,
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new CodexGatewayException(
                CodexGatewayException::PROTOCOL_MALFORMED,
                false,
                'Codex request could not be serialized.',
                $exception,
            );
        }

        if (
            strlen($encoded)
            > $this->settings->maximumMessageBytes
        ) {
            throw new CodexGatewayException(
                CodexGatewayException::OUTPUT_LIMIT_EXCEEDED,
                false,
                'Codex request exceeds the configured message limit.',
            );
        }

        try {
            $this->input->write(
                $encoded."\n",
            );
        } catch (Throwable $exception) {
            throw new CodexGatewayException(
                CodexGatewayException::WRITE_FAILED,
                true,
                'Codex App Server stdin write failed.',
                $exception,
            );
        }
    }

    /**
     * Drain bounded incremental output and classify every protocol message.
     */
    private function pump(): void
    {
        try {
            $this->process->checkTimeout();
        } catch (ProcessTimedOutException $exception) {
            throw new CodexGatewayException(
                CodexGatewayException::REQUEST_TIMEOUT,
                true,
                'Codex App Server exceeded its process timeout.',
                $exception,
            );
        }

        $stderr = $this->process
            ->getIncrementalErrorOutput();

        if ($stderr !== '') {
            $this->stderrBytesSeen += strlen($stderr);

            if (
                $this->stderrBytesSeen
                > $this->settings->maximumStderrBytes
            ) {
                throw new CodexGatewayException(
                    CodexGatewayException::OUTPUT_LIMIT_EXCEEDED,
                    false,
                    'Codex App Server stderr exceeded the configured bound.',
                );
            }

            /*
             * Intentionally redact and discard stderr here. Durable operational
             * logging belongs to a later explicit persistence boundary.
             */
            $this->redactor->message($stderr);
        }

        $stdout = $this->process
            ->getIncrementalOutput();

        if ($stdout === '') {
            return;
        }

        foreach ($this->decoder->push($stdout) as $message) {
            $this->acceptMessage(
                $message,
            );
        }
    }

    /**
     * Correlate responses or enqueue allowed server-originated messages.
     *
     * @param  array<string, mixed>  $message
     */
    private function acceptMessage(
        array $message,
    ): void {
        if (! array_key_exists('method', $message)) {
            $key = $this->identifierKey(
                $message['id'],
            );

            if (isset($this->seenResponseIds[$key])) {
                throw new CodexGatewayException(
                    CodexGatewayException::DUPLICATE_MESSAGE,
                    false,
                    'Codex App Server emitted a duplicate response identifier.',
                );
            }

            $this->seenResponseIds[$key] = true;
            $this->responses[$key] = $message;

            return;
        }

        $method = (string) $message['method'];

        if (! in_array(
            $method,
            $this->settings->allowedServerMethods,
            true,
        )) {
            throw new CodexGatewayException(
                CodexGatewayException::PROTOCOL_UNKNOWN_MESSAGE,
                false,
                'Codex App Server emitted an unapproved protocol method.',
            );
        }

        $requestId = $message['id'] ?? null;

        if ($requestId !== null) {
            $requestKey = $this->identifierKey(
                $requestId,
            );

            if (isset($this->seenServerRequestIds[$requestKey])) {
                throw new CodexGatewayException(
                    CodexGatewayException::DUPLICATE_MESSAGE,
                    false,
                    'Codex App Server emitted a duplicate server request identifier.',
                );
            }

            $this->seenServerRequestIds[$requestKey] = true;
        }

        $this->eventsSeen++;

        if ($this->eventsSeen > $this->settings->maximumEvents) {
            throw new CodexGatewayException(
                CodexGatewayException::OUTPUT_LIMIT_EXCEEDED,
                false,
                'Codex App Server exceeded the configured event count.',
            );
        }

        $payload = isset($message['params'])
            && is_array($message['params'])
            ? $this->redactor->values(
                $message['params'],
            )
            : [];

        $this->sequence++;

        $threadId = $this->nestedIdentifier(
            $payload,
            'threadId',
            'thread',
        );

        $turnId = $this->nestedIdentifier(
            $payload,
            'turnId',
            'turn',
        );

        $itemId = $this->nestedIdentifier(
            $payload,
            'itemId',
            'item',
        );

        $cursor = isset($payload['cursor'])
            && is_string($payload['cursor'])
            ? $payload['cursor']
            : null;

        $fingerprint = $this->fingerprint([
            'method' => $method,
            'request_id' => $requestId,
            'thread_id' => $threadId,
            'turn_id' => $turnId,
            'item_id' => $itemId,
            'payload' => $payload,
        ]);

        $this->events->enqueue(
            new CodexGatewayEvent(
                providerSessionId: $this->context
                    ->providerSessionId,
                executionId: $this->context
                    ->executionId,
                executionAttemptId: $this->context
                    ->executionAttemptId,
                sequence: $this->sequence,
                method: $method,
                requestId: $requestId,
                threadId: $threadId,
                turnId: $turnId,
                itemId: $itemId,
                providerCursor: $cursor,
                payload: $payload,
                fingerprintSha256: $fingerprint,
                occurredAt: CarbonImmutable::now(),
            ),
        );
    }

    /**
     * Return a successful response result or throw a classified RPC failure.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function result(
        array $response,
    ): array {
        if (isset($response['error'])) {
            $error = $response['error'];

            $code = is_array($error)
                && isset($error['code'])
                && is_int($error['code'])
                ? $error['code']
                : 0;

            if ($code === -32001) {
                throw new CodexGatewayException(
                    CodexGatewayException::SERVER_OVERLOADED,
                    true,
                    'Codex App Server is temporarily overloaded.',
                );
            }

            throw new CodexGatewayException(
                CodexGatewayException::RPC_ERROR,
                false,
                'Codex App Server returned a protocol error.',
            );
        }

        $result = $response['result'] ?? null;

        if (! is_array($result)) {
            throw new CodexGatewayException(
                CodexGatewayException::PROTOCOL_MALFORMED,
                false,
                'Codex App Server result must be an object.',
            );
        }

        return $result;
    }

    /**
     * Read one required string from a result object.
     *
     * @param  array<string, mixed>  $result
     */
    private function requiredResultString(
        array $result,
        string $key,
    ): string {
        $value = $result[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new CodexGatewayException(
                CodexGatewayException::PROTOCOL_MALFORMED,
                false,
                "Codex App Server result is missing {$key}.",
            );
        }

        return $value;
    }

    /**
     * Extract an identifier either directly or from a nested protocol object.
     *
     * @param  array<string, mixed>  $payload
     */
    private function nestedIdentifier(
        array $payload,
        string $directKey,
        string $nestedKey,
    ): ?string {
        $direct = $payload[$directKey] ?? null;

        if (is_string($direct) && $direct !== '') {
            return $direct;
        }

        $nested = $payload[$nestedKey] ?? null;

        if (
            is_array($nested)
            && isset($nested['id'])
            && is_string($nested['id'])
            && $nested['id'] !== ''
        ) {
            return $nested['id'];
        }

        return null;
    }

    /**
     * Build a collision-safe array key for an integer or string RPC identifier.
     */
    private function identifierKey(
        int|string $identifier,
    ): string {
        return is_int($identifier)
            ? 'i:'.$identifier
            : 's:'.$identifier;
    }

    /**
     * Generate a deterministic SHA-256 fingerprint for safe protocol content.
     *
     * @param  array<string, mixed>  $value
     */
    private function fingerprint(
        array $value,
    ): string {
        try {
            return hash(
                'sha256',
                json_encode(
                    $this->canonicalize($value),
                    JSON_THROW_ON_ERROR
                        | JSON_UNESCAPED_SLASHES,
                ),
            );
        } catch (JsonException $exception) {
            throw new CodexGatewayException(
                CodexGatewayException::PROTOCOL_MALFORMED,
                false,
                'Codex event fingerprint could not be generated.',
                $exception,
            );
        }
    }

    /**
     * Recursively sort object keys while preserving list order.
     */
    private function canonicalize(
        mixed $value,
    ): mixed {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => $this
                    ->canonicalize($item),
                $value,
            );
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize(
                $item,
            );
        }

        return $value;
    }

    /**
     * Reject operations before the required App Server handshake completes.
     */
    private function assertInitialized(): void
    {
        if (! $this->initialized) {
            throw new CodexGatewayException(
                CodexGatewayException::PROTOCOL_MALFORMED,
                false,
                'Codex App Server connection is not initialized.',
            );
        }
    }
}
