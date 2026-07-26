# Execution Resilience

## Purpose

The execution resilience manager owns deterministic attempt creation, bounded
retries, timeout recovery, and cancellation.

Laravel queue state is transport infrastructure. The `executions` and
`execution_attempts` tables remain the authoritative workflow state.

## Retry semantics

`retry_limit` means the number of retries after the initial attempt.

Examples:

| Retry limit | Maximum attempts |
|---:|---:|
| 0 | 1 |
| 1 | 2 |
| 3 | 4 |

A retryable failure may schedule another attempt only when:

```text
current attempt count <= retry limit
```

A non-retryable failure immediately transitions the execution to failed.

### Backoff

The retry delay uses bounded exponential growth:

```txt
base
base * 2
base * 4
...
max delay
```

Jitter is derived from:

```txt
execution ID + next attempt number
```

It is therefore stable across command replay and deterministic tests.

### Attempt creation

Attempt creation:

1. Locks the execution row.
2. Confirms the execution is queued.
3. Confirms cancellation is not pending.
4. Allocates attempt_count + 1.
5. Creates the attempt.
6. Updates the execution summary.
7. Appends audit and domain events.
8. Commits all changes atomically.

Two callers cannot successfully allocate the same attempt number.

### Timeouts

Every running attempt has a persisted `deadline_at`.

The `executions:recover` command scans a bounded set of expired running
attempts and marks them `timed_out`.

A timed-out attempt follows the same bounded retry policy as another retryable
failure.

### Cancellation

Cancellation is immediate for queued, waiting, blocked, and retry-scheduled
executions.

Cancellation is cooperative for running attempts:

```txt
request cancellation
 -> provider stops or deadline expires
 -> confirm cancellation
 -> attempt and execution become cancelled
```

Cancellation wins over a racing completion, failure, or timeout retry.

### Scheduler

The recovery command runs every minute with scheduler-level overlap and
single-server locks.

Database row locks remain the correctness mechanism. Scheduler locks only
avoid unnecessary duplicate scans.

### Security

Only redacted error and cancellation messages may be persisted.

Provider credentials, prompts, request headers, access tokens, and raw
provider payloads must never be written to execution error fields.

### Deferred work

The following remains outside AIOS-055:

- Dead-letter and manual replay controls
- Provider dispatch
- Provider-specific cancellation adapters
- Recovery-center UI
- Notification delivery
- Evidence and artifact persistence
