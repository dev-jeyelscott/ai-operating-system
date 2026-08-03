# Office projection rebuild

## Purpose

Rebuild derived office projections without changing authoritative workflow state.

## Trigger conditions and severity guidance

Use for projection lag, corruption, or dashboard/office inconsistency. A broad rebuild requires operations-owner approval.

## Required authorization and safety constraints

Use the smallest scope first. This replaces derived state only; never use it to repair authoritative project, ticket, or execution records.

## Preconditions and diagnosis

Capture project or organization scope, checkpoint, current authoritative state, and symptoms. Confirm the issue persists after normal refresh/reconnect.

## Containment and recovery procedure

```bash
./vendor/bin/sail artisan office:projections:rebuild --project=PROJECT_ID --chunk=25
./vendor/bin/sail artisan office:projections:rebuild --organization=ORGANIZATION_ID --chunk=100
./vendor/bin/sail artisan office:projections:rebuild --chunk=100
```

Use organization or global scope only when the smaller scope cannot resolve the issue.

## Verification

Confirm processed/failed counts, checkpoint, project-ticket-execution state, dashboard-office consistency, refresh consistency, Reverb reconnect consistency, and unchanged authoritative state.

## Rollback or abort conditions

Abort on unexpected authoritative writes, failed records requiring investigation, or a worsening projection discrepancy.

## Evidence to retain, escalation, and follow-up actions

Retain scope, counts, checkpoint, command output, and screenshots or traces. Escalate repeat rebuilds for root-cause analysis.
