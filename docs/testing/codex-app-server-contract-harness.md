# Codex App Server Contract Harness

## Purpose

Normal automated tests must validate the supported Codex App Server protocol without using the real Codex executable, external credentials, provider billing, or external network access.

The deterministic harness executes through the same `CodexProcessGateway` and Symfony Process boundary used by production.

## Files

- `tests/Fixtures/Codex/bin/fake-codex`
- `tests/Fixtures/Codex/scenarios.json`
- `tests/Support/Codex/FakeCodexServer.php`
- `tests/Feature/Infrastructure/Codex/Process/SymfonyCodexProcessGatewayContractTest.php`
- `bin/check-codex-test-isolation`

## Protocol

The harness speaks newline-delimited JSON over stdin/stdout.

It follows the supported AIOS Codex App Server subset:

1. `initialize`
2. `initialized`
3. `thread/start`
4. `turn/start`
5. server notifications
6. provider approval requests
7. application approval responses
8. `turn/interrupt`
9. terminal events
10. process shutdown

The transport does not add a JSON-RPC `jsonrpc` field because the supported App Server wire protocol omits it.

## Determinism

The fixture catalog owns:

- fixture format version;
- protocol version;
- seed;
- fixed clock;
- partial write size;
- delays;
- output limits;
- exit codes;
- scenario behavior.

Application tests that require deterministic timestamps should freeze Carbon to `FakeCodexServer::fixedClock()`.

## Required Scenario Categories

Every supported provider behavior must have deterministic fixtures covering its normal and failure paths before application orchestration is added.

Required baseline scenarios include:

- success;
- streaming;
- approval accepted;
- approval denied;
- approval blocked;
- malformed protocol;
- request timeout;
- cancellation;
- process crash;
- duplicate response;
- duplicate event;
- out-of-order event;
- oversized output;
- stale heartbeat;
- recovery;
- forced termination;
- stderr pressure;
- unknown message;
- server overload;
- generic RPC error;
- redaction.

## Adding Provider Behavior

When AIOS begins supporting another App Server method:

1. Update the supported production method allowlist only when the method is approved.
2. Add or extend the deterministic fake scenario first.
3. Add gateway contract coverage.
4. Add persistence and replay coverage when the event is durable.
5. Add approval-policy coverage when the method is consequential.
6. Add cancellation/recovery coverage when it creates long-lived provider state.
7. Run focused tests.
8. Run `composer ci:check`.

Do not add a live provider call to the normal quality workflow.

## Live Smoke Tests

Live smoke tests must remain separate, explicit, optional, and credential-gated.

They must never be required for:

- pull requests;
- normal `quality` CI;
- local unit tests;
- local feature tests;
- static analysis;
- frontend quality gates.

## Production Safety

The repository-owned fake executable is rejected by production Codex App Server configuration.

The fake executable also requires the explicit test-only environment marker:

`AIOS_CODEX_FAKE_SERVER=1`

This prevents accidental use outside the test harness.
