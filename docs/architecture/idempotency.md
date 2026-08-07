# Command Idempotency

## Purpose

The idempotency-key service prevents duplicate execution of application
commands that explicitly implement `IdempotentCommand`.

The service decorates the existing synchronous `CommandBus`. Normal commands
remain unchanged.

## Correctness Model

Redis atomic locks coordinate concurrent callers, but Redis is not the durable
source of truth.

PostgreSQL owns correctness through:

- one durable row per `scope + SHA-256(key)`;
- a unique database constraint;
- row-level locks during claim and completion;
- encrypted terminal `CommandResult` persistence;
- processing ownership and expiry timestamps.

## Command Contract

An idempotent command supplies:

- `idempotencyKey()`;
- `idempotencyScope()`;
- `idempotencyPayload()`.

Scopes must contain the explicit tenant and operation boundary.

Recommended format:

```text
organization:{organizationId}:project:{projectId}:{operation}
```

The raw key is never stored.

The payload must:

- contain only stable command inputs;
- use string keys for object-like arrays;
- exclude credentials and secrets;
- exclude raw files and private document bodies;
- serialize deterministically.

### Result Behavior

For the same active scope and key:

| Situation                           | Result                          |
| ----------------------------------- | ------------------------------- |
| Same command and payload completed  | Replay original terminal result |
| Different command or payload        | Conflict                        |
| Original command still processing   | Retryable failure               |
| Processing claim expired            | Reclaim and execute             |
| Previous result expired             | Reclaim and execute             |
| Operation returns retryable failure | Release claim                   |
| Operation throws                    | Release claim and rethrow       |

Succeeded, validation-failed, and conflict results are terminal and replayable.

Retryable failures are intentionally not persisted as completed results.

### External Side Effects

Command idempotency does not replace provider-side idempotency.

Commands performing external writes must still pass stable external request
keys to Notion, GitHub, storage, payment, email, or other providers and persist
their external request IDs and reconciliation state.

### Security

- Raw idempotency keys are hashed using SHA-256.
- Stored command results use Laravel's encrypted array cast.
- Lock names contain hashes, not raw keys.
- Conflict and retry responses never expose raw keys.
- Command payloads must not contain secrets.
- Scopes must include explicit organization and project boundaries.

### Operational Limits

The default processing claim is 15 minutes.

Long-running work should create an execution record and dispatch asynchronous
work. It should not keep the application command open for the complete provider
execution.

Completed results remain replayable for 24 hours by default.

Automatic pruning is intentionally outside AIOS-057.
