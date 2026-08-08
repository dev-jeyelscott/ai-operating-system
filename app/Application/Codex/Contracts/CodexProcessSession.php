<?php

declare(strict_types=1);

namespace App\Application\Codex\Contracts;

use App\Application\Codex\Data\CodexGatewayEvent;
use App\Application\Codex\Data\CodexGatewayInitialization;
use App\Application\Codex\Data\CodexProcessStatus;
use App\Application\Codex\Data\CodexThreadReference;
use App\Application\Codex\Data\CodexTurnReference;
use App\Application\Codex\Data\CodexTurnRequest;

/**
 * Defines one bounded, bidirectional Codex App Server process session.
 */
interface CodexProcessSession
{
    /** Complete the mandatory protocol handshake. */
    public function initialize(): CodexGatewayInitialization;

    /** Start one provider thread using application-owned policy. */
    public function startThread(string $approvalPolicy): CodexThreadReference;

    /** Start one schema-constrained provider turn. */
    public function startTurn(CodexTurnRequest $request): CodexTurnReference;

    /**
     * Send one validated JSON-RPC result to a provider-originated approval.
     *
     * @param  array<string, mixed>  $result
     */
    public function respondToApproval(
        int|string $requestId,
        array $result,
    ): void;

    /** Request idempotent interruption of one active turn. */
    public function interruptTurn(
        string $threadId,
        string $turnId,
    ): void;

    /** Poll one normalized provider event. */
    public function nextEvent(
        int $timeoutMilliseconds = 250,
    ): ?CodexGatewayEvent;

    /** Return transient process liveness. */
    public function status(): CodexProcessStatus;

    /** Shut down the provider process idempotently. */
    public function shutdown(): void;
}
