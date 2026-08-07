# Stuck ticket lease

## Purpose

Recover expired ticket leases without duplicate execution.

## Trigger conditions and severity guidance

Use when a lease has passed its deadline or a ticket remains claimed after a worker failure. Treat uncertain worker liveness as elevated severity.

## Required authorization and safety constraints

An authorized operator must use the supported recovery command. Never delete lease rows directly.

## Preconditions and diagnosis

Identify lease owner, ticket, execution, attempt, heartbeat, timeout, current workflow state, retry limit, and budget. Confirm no worker still processes it and liveness policy permits reclamation.

## Containment and recovery procedure

```bash
./vendor/bin/sail artisan executions:recover --limit=200
```

## Verification

Confirm the old lease is released or expired, exactly one later claim is possible, budget/retry policy permits it, lifecycle audit events exist, and no duplicate development artifact was created.

## Rollback or abort conditions

Abort if a live worker owns the lease or audit/state evidence is inconsistent.

## Evidence to retain, escalation, and follow-up actions

Retain execution identifiers, timestamps, command output, and outcome. Escalate repeated expiry or suspected duplicate artifacts for engineering investigation.
