# Dead-letter recovery

## Purpose

Safely inspect and replay terminal outbox or allowlisted consumer delivery failures.

## Trigger conditions and severity guidance

Use after bounded retry reaches a dead letter. Escalate immediately if a failure affects security, tenant isolation, or an external side effect.

## Required authorization and safety constraints

Only an authorized operator may replay. Fix the root cause first, replay one record at a time, require a named actor and reason, and never display stored payloads unnecessarily.

## Preconditions and diagnosis

```bash
./vendor/bin/sail artisan dead-letters:list --source=all --limit=50
```

Identify the safe source, ID, failure category, prior side-effect mapping, and idempotency boundary.

## Containment and recovery procedure

```bash
./vendor/bin/sail artisan dead-letters:replay outbox EVENT_ID \
  --actor=OPERATOR_IDENTIFIER \
  --reason='Root cause fixed and idempotency verified' --yes
```

## Verification

Verify the consumption marker and side-effect mapping. Stop if duplicate external state is detected.

## Rollback or abort conditions

Abort when root cause, idempotency, or existing side-effect state cannot be established.

## Evidence to retain, escalation, and follow-up actions

Retain safe command output, actor, reason, IDs, and verification result. Escalate duplicates or repeat failures and add corrective work.
