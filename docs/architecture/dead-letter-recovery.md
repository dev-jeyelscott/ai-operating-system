# Dead-letter and manual replay controls

## Scope

AIOS-056 provides CLI operator controls for inspecting and safely replaying
terminal domain-event delivery failures.

It does not provide the later Inertia recovery center, automatic infinite
replay, arbitrary Laravel job replay, or replay of external side effects.

## Sources

Two durable failure sources are supported:

1. Unpublished `outbox_messages` records with `dead_lettered_at`.
2. Laravel `failed_jobs` records containing `ConsumeOutboxMessage`.

Other failed Laravel jobs are intentionally not eligible.

## Outbox lifecycle

```text
available
 -> reserved
 -> published

available
 -> reserved
 -> retry scheduled
 -> reserved
 -> dead-lettered
 -> manual replay
 -> available with a fresh bounded attempt cycle
 ```

Manual replay clears the current dispatch-attempt counter but preserves:

- replay_count
- last_replayed_at
- the append-only audit event
- the original event envelope and identity

### Queue-job replay

The replay service validates that the failed payload identifies
ConsumeOutboxMessage, reconstructs only that allowlisted class, resolves its
authoritative outbox event, and publishes a fresh consumer job.

The original serialized queue payload is never blindly executed.

The replacement job is published before the failed record is forgotten. A
process crash between those operations may create a duplicate consumer job, but
the existing deduplicated consumer contract prevents duplicate domain effects.
Deleting the failed record first could permanently lose the event.

### Operator controls

```bash
php artisan dead-letters:list --source=all --limit=50

php artisan dead-letters:replay outbox <event-id> \
    --actor=<operator-id> \
    --reason="<recovery rationale>"

php artisan dead-letters:replay queue <failed-job-uuid> \
    --actor=<operator-id> \
    --reason="<recovery rationale>"
```

Every replay requires an operator identifier, a reason, and an append-only
audit event.

### Security

- Raw event envelopes are not displayed.
- Serialized commands are not displayed.
- Full exception messages and stack traces are not displayed.
- Only safe exception-type summaries are displayed.
- Unknown queue jobs fail closed.
- Audit metadata is processed by the centralized sensitive-value redactor.
- Shell access remains the operator authorization boundary for Phase 4.

The project-scoped HTTP recovery center and user-policy authorization belong to
AIOS-122 and must consume this application service rather than duplicating its
replay rules.
