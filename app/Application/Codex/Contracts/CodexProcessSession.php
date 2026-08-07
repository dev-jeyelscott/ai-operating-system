<?php

declare(strict_types=1);

namespace App\Application\Codex\Contracts;

use App\Application\Codex\Data\CodexGatewayEvent;
use App\Application\Codex\Data\CodexGatewayInitialization;
use App\Application\Codex\Data\CodexProcessStatus;
use App\Application\Codex\Data\CodexThreadReference;
use App\Application\Codex\Data\CodexTurnReference;

/**
 * Provides typed operations for one live Codex App Server process.
 */
interface CodexProcessSession
{
    /**
     * Complete the mandatory App Server initialization handshake.
     */
    public function initialize(): CodexGatewayInitialization;

    /**
     * Start one new Codex thread using the immutable process context.
     */
    public function startThread(
        string $approvalPolicy,
    ): CodexThreadReference;

    /**
     * Begin one turn in an existing provider thread.
     *
     * @param  list<array<string, mixed>>  $input
     */
    public function startTurn(
        string $threadId,
        array $input,
    ): CodexTurnReference;

    /**
     * Return an authorized answer to one provider-initiated approval request.
     */
    public function respondToApproval(
        int|string $requestId,
        string $decision,
    ): void;

    /**
     * Request interruption of one active turn.
     */
    public function interruptTurn(
        string $threadId,
        string $turnId,
    ): void;

    /**
     * Return the next ordered provider event or null when the polling window
     * expires without an event.
     */
    public function nextEvent(
        int $timeoutMilliseconds = 250,
    ): ?CodexGatewayEvent;

    /**
     * Return transient process status for liveness checks.
     */
    public function status(): CodexProcessStatus;

    /**
     * Close stdin, wait a bounded grace period, then force-stop when required.
     */
    public function shutdown(): void;
}
