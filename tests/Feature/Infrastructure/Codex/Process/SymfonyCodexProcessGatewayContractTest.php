<?php

declare(strict_types=1);

use App\Application\Codex\Contracts\CodexProcessGateway;
use App\Application\Codex\Contracts\CodexProcessSession;
use App\Application\Codex\Data\CodexGatewayEvent;
use App\Application\Codex\Exceptions\CodexGatewayException;
use App\Application\Security\RedactSensitiveData;
use App\Infrastructure\Codex\Process\CodexAppServerSettings;
use App\Infrastructure\Codex\Process\CodexJsonRpcDecoder;
use App\Infrastructure\Codex\Process\SymfonyCodexProcessSession;
use Carbon\CarbonImmutable;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\Codex\FakeCodexServer;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(
        FakeCodexServer::fixedClock(),
    );
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    config()->set(
        'app.env',
        'testing',
    );

    FakeCodexServer::configureDefaults();
});

/**
 * Open one real Symfony-backed process session using a deterministic fixture.
 *
 * @param  array<string, mixed>  $configurationOverrides
 * @return array{
 *     session: CodexProcessSession,
 *     record_path: string
 * }
 */
function aios248OpenSession(
    string $scenario,
    array $configurationOverrides = [],
): array {
    $recordPath = FakeCodexServer::useScenario(
        $scenario,
        $configurationOverrides,
    );

    $session = app(
        CodexProcessGateway::class,
    )->start(
        FakeCodexServer::context(),
    );

    return [
        'session' => $session,
        'record_path' => $recordPath,
    ];
}

/**
 * Poll until a target provider method is observed.
 *
 * @return list<CodexGatewayEvent>
 */
function aios248CollectUntil(
    CodexProcessSession $session,
    string $terminalMethod,
): array {
    $events = [];

    for ($attempt = 0; $attempt < 50; $attempt++) {
        $event = $session->nextEvent(100);

        if ($event === null) {
            continue;
        }

        $events[] = $event;

        if ($event->method === $terminalMethod) {
            return $events;
        }
    }

    throw new RuntimeException(
        "Did not receive {$terminalMethod}.",
    );
}

/**
 * Build one minimal schema-constrained request for gateway contract tests.
 */
function aios248TurnRequest(
    string $threadId,
): CodexTurnRequest {
    return new CodexTurnRequest(
        threadId: $threadId,
        input: [
            [
                'type' => 'text',
                'text' => 'Plan AIOS-248.',
            ],
        ],
        reasoningEffort: 'medium',
        outputSchema: [
            'type' => 'object',
        ],
    );
}

/**
 * Execute one complete deterministic planning-shaped Codex turn.
 *
 * @return array{
 *     events: list<array<string, mixed>>,
 *     requests: list<array<string, mixed>>
 * }
 */
function aios248PlanningTurn(
    string $scenario = 'success',
): array {
    $fixture = aios248OpenSession($scenario);
    $session = $fixture['session'];

    try {
        $initialization = $session->initialize();

        expect($initialization->userAgent)
            ->toBe('aios-fake-codex/1')
            ->and($initialization->binaryVersion)
            ->toBe(FakeCodexServer::BINARY_VERSION)
            ->and($initialization->protocolVersion)
            ->toBe('stable');

        $thread = $session->startThread(
            'on-request',
        );

        expect($thread->id)
            ->toBe('thread-248');

        $session->startTurn(
            aios248TurnRequest($thread->id),
        );

        expect($turn->id)
            ->toBe('turn-248');

        $events = aios248CollectUntil(
            $session,
            'turn/completed',
        );

        return [
            'events' => array_map(
                static fn (
                    CodexGatewayEvent $event,
                ): array => [
                    'sequence' => $event->sequence,
                    'method' => $event->method,
                    'request_id' => $event->requestId,
                    'thread_id' => $event->threadId,
                    'turn_id' => $event->turnId,
                    'item_id' => $event->itemId,
                    'payload' => $event->payload,
                    'fingerprint' => $event->fingerprintSha256,
                    'occurred_at' => $event->occurredAt->toIso8601String(),
                ],
                $events,
            ),
            'requests' => FakeCodexServer::recordedMessages(
                $fixture['record_path'],
            ),
        ];
    } finally {
        $session->shutdown();
    }
}

test(
    'complete planning-shaped turn preserves protocol ordering and correlation',
    function (): void {
        $result = aios248PlanningTurn();

        expect(
            array_column(
                $result['events'],
                'method',
            ),
        )->toBe([
            'turn/started',
            'item/started',
            'item/agentMessage/delta',
            'item/completed',
            'thread/tokenUsage/updated',
            'turn/completed',
        ]);

        expect(
            array_column(
                $result['events'],
                'sequence',
            ),
        )->toBe([
            1,
            2,
            3,
            4,
            5,
            6,
        ]);

        foreach ($result['events'] as $event) {
            expect($event['fingerprint'])
                ->toHaveLength(64)
                ->and($event['occurred_at'])
                ->toBe(
                    FakeCodexServer::fixedClock()
                        ->toIso8601String(),
                );
        }

        expect($result['requests'][0])
            ->toMatchArray([
                'id' => 1,
                'method' => 'initialize',
            ])
            ->and($result['requests'][1])
            ->toBe([
                'method' => 'initialized',
            ])
            ->and($result['requests'][2]['method'])
            ->toBe('thread/start')
            ->and($result['requests'][2]['params']['model'])
            ->toBe('gpt-5.3-codex')
            ->and($result['requests'][2]['params']['approvalPolicy'])
            ->toBe('on-request')
            ->and($result['requests'][2]['params']['sandbox'])
            ->toBe('workspaceWrite')
            ->and($result['requests'][3]['method'])
            ->toBe('turn/start');
    },
);

test(
    'same fixture produces identical deterministic provider events',
    function (): void {
        $first = aios248PlanningTurn();
        $second = aios248PlanningTurn();

        expect($second['events'])
            ->toBe($first['events']);
    },
);

test(
    'partial stdout writes are reassembled without changing the contract',
    function (): void {
        $result = aios248PlanningTurn(
            'streaming',
        );

        expect(
            array_column(
                $result['events'],
                'method',
            ),
        )->toContain(
            'item/agentMessage/delta',
        )->toContain(
            'turn/completed',
        );
    },
);

test(
    'provider approval is delivered exactly once',
    function (
        string $scenario,
        string $decision,
        string $terminalStatus,
    ): void {
        $fixture = aios248OpenSession(
            $scenario,
        );

        $session = $fixture['session'];

        try {
            $session->initialize();

            $thread = $session->startThread(
                'on-request',
            );

            $session->startTurn(
                aios248TurnRequest($thread->id),
            );

            $events = aios248CollectUntil(
                $session,
                'item/commandExecution/requestApproval',
            );

            $approval = $events[array_key_last($events)];

            expect($approval->requestId)
                ->toBe(7248)
                ->and($approval->threadId)
                ->toBe($thread->id)
                ->and($approval->turnId)
                ->toBe($turn->id);

            $session->respondToApproval(
                $approval->requestId,
                [
                    'decision' => $decision,
                ],
            );

            // Same decision is deliberately replay-safe.
            $session->respondToApproval(
                $approval->requestId,
                [
                    'decision' => $decision,
                ],
            );

            $terminalEvents = aios248CollectUntil(
                $session,
                'turn/completed',
            );

            $terminal = $terminalEvents[array_key_last($terminalEvents)];

            expect(
                $terminal->payload['turn']['status']
                    ?? null,
            )->toBe($terminalStatus);

            $approvalResponses = array_values(
                array_filter(
                    FakeCodexServer::recordedMessages(
                        $fixture['record_path'],
                    ),
                    static fn (
                        array $message,
                    ): bool => ($message['id'] ?? null) === 7248
                        && ! isset($message['method']),
                ),
            );

            expect($approvalResponses)
                ->toHaveCount(1);
        } finally {
            $session->shutdown();
        }
    },
)->with([
    'approved request' => [
        'approval_accept',
        'accept',
        'completed',
    ],
    'denied request' => [
        'approval_denial',
        'decline',
        'failed',
    ],
]);

test(
    'approval may remain blocked without creating an implicit decision',
    function (): void {
        $fixture = aios248OpenSession(
            'approval_blocked',
        );

        $session = $fixture['session'];

        try {
            $session->initialize();

            $thread = $session->startThread(
                'on-request',
            );

            $session->startTurn(
                aios248TurnRequest($thread->id),
            );

            aios248CollectUntil(
                $session,
                'item/commandExecution/requestApproval',
            );

            expect($session->nextEvent(50))
                ->toBeNull()
                ->and($session->status()->running)
                ->toBeTrue();
        } finally {
            $session->shutdown();
        }
    },
);

test(
    'turn interruption is idempotent',
    function (): void {
        $fixture = aios248OpenSession(
            'cancellation',
        );

        $session = $fixture['session'];

        try {
            $session->initialize();

            $thread = $session->startThread(
                'on-request',
            );

            $session->startTurn(
                aios248TurnRequest($thread->id),
            );

            aios248CollectUntil(
                $session,
                'turn/started',
            );

            $session->interruptTurn(
                $thread->id,
                $turn->id,
            );

            $session->interruptTurn(
                $thread->id,
                $turn->id,
            );

            $terminalEvents = aios248CollectUntil(
                $session,
                'turn/completed',
            );

            $terminal = $terminalEvents[array_key_last($terminalEvents)];

            expect(
                $terminal->payload['turn']['status']
                    ?? null,
            )->toBe('interrupted');

            $interrupts = array_values(
                array_filter(
                    FakeCodexServer::recordedMessages(
                        $fixture['record_path'],
                    ),
                    static fn (
                        array $message,
                    ): bool => ($message['method'] ?? null)
                        === 'turn/interrupt',
                ),
            );

            expect($interrupts)
                ->toHaveCount(1);
        } finally {
            $session->shutdown();
        }
    },
);

test(
    'malformed provider JSON is classified as terminal protocol failure',
    function (): void {
        $fixture = aios248OpenSession(
            'malformed_payload',
        );

        try {
            $fixture['session']->initialize();

            $this->fail(
                'Expected malformed protocol failure.',
            );
        } catch (CodexGatewayException $exception) {
            expect($exception->failureCode)
                ->toBe(
                    CodexGatewayException::PROTOCOL_MALFORMED,
                )
                ->and($exception->retryable)
                ->toBeFalse();
        } finally {
            $fixture['session']->shutdown();
        }
    },
);

test(
    'duplicate response identifiers fail closed',
    function (): void {
        $fixture = aios248OpenSession(
            'duplicate_response',
        );

        try {
            $fixture['session']->initialize();

            $this->fail(
                'Expected duplicate response failure.',
            );
        } catch (CodexGatewayException $exception) {
            expect($exception->failureCode)
                ->toBe(
                    CodexGatewayException::DUPLICATE_MESSAGE,
                )
                ->and($exception->retryable)
                ->toBeFalse();
        } finally {
            $fixture['session']->shutdown();
        }
    },
);

test(
    'startup request timeout is retryable',
    function (): void {
        $fixture = aios248OpenSession(
            'request_timeout',
            [
                'startup_timeout_seconds' => 1,
            ],
        );

        try {
            $fixture['session']->initialize();

            $this->fail(
                'Expected request timeout.',
            );
        } catch (CodexGatewayException $exception) {
            expect($exception->failureCode)
                ->toBe(
                    CodexGatewayException::REQUEST_TIMEOUT,
                )
                ->and($exception->retryable)
                ->toBeTrue();
        } finally {
            $fixture['session']->shutdown();
        }
    },
);

test(
    'server overload is explicitly retryable',
    function (): void {
        $fixture = aios248OpenSession(
            'server_overloaded',
        );

        try {
            $fixture['session']->initialize();

            $this->fail(
                'Expected overloaded-provider failure.',
            );
        } catch (CodexGatewayException $exception) {
            expect($exception->failureCode)
                ->toBe(
                    CodexGatewayException::SERVER_OVERLOADED,
                )
                ->and($exception->retryable)
                ->toBeTrue();
        } finally {
            $fixture['session']->shutdown();
        }
    },
);

test(
    'generic RPC errors are terminal',
    function (): void {
        $fixture = aios248OpenSession(
            'rpc_error',
        );

        try {
            $fixture['session']->initialize();

            $this->fail(
                'Expected RPC failure.',
            );
        } catch (CodexGatewayException $exception) {
            expect($exception->failureCode)
                ->toBe(
                    CodexGatewayException::RPC_ERROR,
                )
                ->and($exception->retryable)
                ->toBeFalse();
        } finally {
            $fixture['session']->shutdown();
        }
    },
);

test(
    'unknown provider methods fail closed',
    function (): void {
        $fixture = aios248OpenSession(
            'unknown_message',
        );

        $session = $fixture['session'];

        try {
            $session->initialize();

            $thread = $session->startThread(
                'on-request',
            );

            try {
                $session->startTurn(
                    aios248TurnRequest($thread->id),
                );

                $session->nextEvent(100);

                $this->fail(
                    'Expected unknown-message failure.',
                );
            } catch (CodexGatewayException $exception) {
                expect($exception->failureCode)
                    ->toBe(
                        CodexGatewayException::PROTOCOL_UNKNOWN_MESSAGE,
                    );
            }
        } finally {
            $session->shutdown();
        }
    },
);

test(
    'oversized provider output is rejected',
    function (): void {
        $fixture = aios248OpenSession(
            'oversized_output',
            [
                'maximum_message_bytes' => 256,
            ],
        );

        $session = $fixture['session'];

        try {
            $session->initialize();

            $thread = $session->startThread(
                'on-request',
            );

            try {
                $session->startTurn(
                    aios248TurnRequest($thread->id),
                );

                $session->nextEvent(100);

                $this->fail(
                    'Expected output-limit failure.',
                );
            } catch (CodexGatewayException $exception) {
                expect($exception->failureCode)
                    ->toBe(
                        CodexGatewayException::OUTPUT_LIMIT_EXCEEDED,
                    );
            }
        } finally {
            $session->shutdown();
        }
    },
);

test(
    'stderr pressure remains bounded',
    function (): void {
        $fixture = aios248OpenSession(
            'stderr_pressure',
            [
                'maximum_stderr_bytes' => 256,
            ],
        );

        $session = $fixture['session'];

        try {
            $session->initialize();

            $thread = $session->startThread(
                'on-request',
            );

            try {
                $session->startTurn(
                    aios248TurnRequest($thread->id),
                );

                $session->nextEvent(100);

                $this->fail(
                    'Expected stderr-limit failure.',
                );
            } catch (CodexGatewayException $exception) {
                expect($exception->failureCode)
                    ->toBe(
                        CodexGatewayException::OUTPUT_LIMIT_EXCEEDED,
                    );
            }
        } finally {
            $session->shutdown();
        }
    },
);

test(
    'crashed provider process is classified as retryable',
    function (): void {
        $fixture = aios248OpenSession(
            'crash',
        );

        $session = $fixture['session'];

        try {
            $session->initialize();

            $thread = $session->startThread(
                'on-request',
            );

            $session->startTurn(
                aios248TurnRequest($thread->id),
            );

            try {
                $session->nextEvent(500);

                $this->fail(
                    'Expected process-exited failure.',
                );
            } catch (CodexGatewayException $exception) {
                expect($exception->failureCode)
                    ->toBe(
                        CodexGatewayException::PROCESS_EXITED,
                    )
                    ->and($exception->retryable)
                    ->toBeTrue();
            }
        } finally {
            $session->shutdown();
        }
    },
);

test(
    'duplicate provider notifications retain equal fingerprints for persistence deduplication',
    function (): void {
        $fixture = aios248OpenSession(
            'duplicate_event',
        );

        $session = $fixture['session'];

        try {
            $session->initialize();

            $thread = $session->startThread(
                'on-request',
            );

            $session->startTurn(
                aios248TurnRequest($thread->id),
            );

            $first = $session->nextEvent(100);
            $second = $session->nextEvent(100);

            expect($first)
                ->not->toBeNull()
                ->and($second)
                ->not->toBeNull()
                ->and($first->sequence)
                ->toBe(1)
                ->and($second->sequence)
                ->toBe(2)
                ->and($first->fingerprintSha256)
                ->toBe(
                    $second->fingerprintSha256,
                );
        } finally {
            $session->shutdown();
        }
    },
);

test(
    'out-of-order notifications remain observable for the persistence policy to reject',
    function (): void {
        $fixture = aios248OpenSession(
            'out_of_order_event',
        );

        $session = $fixture['session'];

        try {
            $session->initialize();

            $thread = $session->startThread(
                'on-request',
            );

            $session->startTurn(
                aios248TurnRequest($thread->id),
            );

            $first = $session->nextEvent(100);
            $second = $session->nextEvent(100);

            expect($first?->method)
                ->toBe('turn/completed')
                ->and($second?->method)
                ->toBe('turn/started');
        } finally {
            $session->shutdown();
        }
    },
);

test(
    'structured provider payload is redacted before crossing the gateway boundary',
    function (): void {
        $fixture = aios248OpenSession(
            'redaction',
        );

        $session = $fixture['session'];

        try {
            $session->initialize();

            $thread = $session->startThread(
                'on-request',
            );

            $session->startTurn(
                aios248TurnRequest($thread->id),
            );

            $warning = $session->nextEvent(100);

            expect($warning?->payload)
                ->toMatchArray([
                    'api_key' => '[REDACTED]',
                    'authorization' => '[REDACTED]',
                    'safe' => 'visible',
                ]);
        } finally {
            $session->shutdown();
        }
    },
);

test(
    'stale-heartbeat fixture remains running without fabricating provider activity',
    function (): void {
        $fixture = aios248OpenSession(
            'stale_heartbeat',
        );

        $session = $fixture['session'];

        try {
            $session->initialize();

            $thread = $session->startThread(
                'on-request',
            );

            $session->startTurn(
                aios248TurnRequest($thread->id),
            );

            expect($session->nextEvent(50))
                ->toBeNull()
                ->and($session->status()->running)
                ->toBeTrue();
        } finally {
            $session->shutdown();
        }
    },
);

test(
    'shutdown force-terminates a provider that ignores graceful EOF',
    function (): void {
        $fixture = aios248OpenSession(
            'forced_termination',
            [
                'shutdown_grace_seconds' => 1,
            ],
        );

        $session = $fixture['session'];

        $session->initialize();

        $thread = $session->startThread(
            'on-request',
        );

        $session->startTurn(
            $thread->id,
            [
                [
                    'type' => 'text',
                    'text' => 'Plan AIOS-248.',
                ],
            ],
        );

        $session->shutdown();

        expect($session->status()->running)
            ->toBeFalse();
    },
);

test(
    'closed stdin is classified as retryable write failure',
    function (): void {
        FakeCodexServer::configureDefaults();

        $settings = app(
            CodexAppServerSettings::class,
        );

        $process = new Process([
            '/bin/sh',
            '-c',
            'sleep 5',
        ]);

        $process->start();

        $input = new InputStream;
        $input->close();

        $session = new SymfonyCodexProcessSession(
            context: FakeCodexServer::context(),
            settings: $settings,
            process: $process,
            input: $input,
            decoder: new CodexJsonRpcDecoder(
                maximumMessageBytes: $settings
                    ->maximumMessageBytes,
                maximumJsonDepth: $settings
                    ->maximumJsonDepth,
            ),
            redactor: app(
                RedactSensitiveData::class,
            ),
        );

        try {
            $session->initialize();

            $this->fail(
                'Expected stdin-write failure.',
            );
        } catch (CodexGatewayException $exception) {
            expect($exception->failureCode)
                ->toBe(
                    CodexGatewayException::WRITE_FAILED,
                )
                ->and($exception->retryable)
                ->toBeTrue();
        } finally {
            $process->stop(0);
        }
    },
);

test(
    'binary version mismatch fails before provider communication begins',
    function (): void {
        FakeCodexServer::configureDefaults();

        config()->set(
            'codex-app-server.expected_binary_version',
            'not-the-fixture-version',
        );

        FakeCodexServer::forgetSettings();

        try {
            app(
                CodexProcessGateway::class,
            )->start(
                FakeCodexServer::context(),
            );

            $this->fail(
                'Expected binary-version mismatch.',
            );
        } catch (CodexGatewayException $exception) {
            expect($exception->failureCode)
                ->toBe(
                    CodexGatewayException::VERSION_MISMATCH,
                )
                ->and($exception->retryable)
                ->toBeFalse();
        }
    },
);

test(
    'failed executable startup receives a stable retryable classification',
    function (): void {
        FakeCodexServer::configureDefaults();

        config()->set(
            'codex-app-server.executable',
            '/bin/false',
        );

        FakeCodexServer::forgetSettings();

        try {
            app(
                CodexProcessGateway::class,
            )->start(
                FakeCodexServer::context(),
            );

            $this->fail(
                'Expected startup failure.',
            );
        } catch (CodexGatewayException $exception) {
            expect($exception->failureCode)
                ->toBe(
                    CodexGatewayException::STARTUP_FAILED,
                )
                ->and($exception->retryable)
                ->toBeTrue();
        }
    },
);

test(
    'production configuration cannot select the deterministic fake executable',
    function (): void {
        FakeCodexServer::configureDefaults();

        config()->set(
            'app.env',
            'production',
        );

        expect(
            static fn (): CodexAppServerSettings => CodexAppServerSettings::fromConfiguration(),
        )->toThrow(
            InvalidArgumentException::class,
            'deterministic Codex test harness cannot be selected in production',
        );
    },
);
