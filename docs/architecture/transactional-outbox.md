# Transactional Outbox

## Status

Implemented by AIOS-050.

## Purpose

The transactional outbox prevents dual-write inconsistencies between the
authoritative PostgreSQL application state and emitted domain events.

A state mutation and its domain-event envelope are persisted inside the same
database transaction. Either both commit or both roll back.

## Canonical write flow

```text
Application command
    |
    v
TransactionalOutbox::run()
    |
    +-- mutate authoritative business state
    |
    +-- create canonical AIOS-049 DomainEventEnvelope
    |
    +-- append envelope to outbox_messages
    |
    v
Commit one PostgreSQL transaction
```

The application must never commit business state and append its event in two
separate transactions.

## Persistence contract

Application code depends on:

```text
App\Application\Events\Contracts\DomainEventOutbox
```

PostgreSQL persistence is implemented by:

```text
App\Infrastructure\Persistence\Repositories\Events\EloquentDomainEventOutbox
```

Atomic transaction orchestration is provided by:

```text
App\Application\Events\TransactionalOutbox
```

## Stored data

Each outbox row stores:

* A monotonic database sequence.
* The unique domain event ID.
* Event and aggregate identity.
* Organization and optional project scope.
* Correlation, causation, and execution IDs.
* Schema version.
* Domain occurrence time.
* The complete canonical JSONB event envelope.
* Outbox persistence time.
* Nullable publication time.

The outbox does not reconstruct events from mutable model state.

## Identifier rules

`event_id` is unique and is the consumer deduplication identity.

`sequence` is a database ordering cursor only. It is not a cross-system event
identity.

`correlation_id` groups related activity.

`causation_id` identifies the event or command that directly caused the event.

`execution_id` links the event to workflow execution when applicable.

## Transaction rules

All writes must use `TransactionalOutbox::run()`.

The infrastructure adapter fails closed when no active database transaction
exists.

An outbox append failure must roll back the related business-state mutation.

A business-operation failure after event append must roll back the outbox row.

## Payload safety

The outbox stores the exact AIOS-049 envelope.

Event producers are responsible for:

* JSON-serializable payloads.
* Bounded payload size.
* Secret and sensitive-value redaction.
* Stable event names.
* Correct schema versions.
* Tenant and project scope.
* Actor, provider, correlation, causation, and execution metadata.

The outbox must not mutate or enrich a canonical envelope.

## Explicit exclusions

AIOS-050 does not implement:

* Outbox polling.
* Message claiming.
* Queue dispatch.
* Retry scheduling.
* Consumer deduplication.
* Dead-letter handling.
* Publication reconciliation.
* Retention or pruning.
* Real-time event streaming.

Those capabilities begin with AIOS-051 and later recovery tickets.
