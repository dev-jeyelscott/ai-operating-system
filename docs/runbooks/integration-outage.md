# Integration outage

## Purpose

Contain and recover Notion, Redis/Reverb, or S3/MinIO integration degradation without duplicate external effects.

## Trigger conditions and severity guidance

Use for Notion timeout, authentication rejection, rate limiting, schema incompatibility, circuit-open state, Redis/Reverb degradation, or storage outage. Widespread synchronization loss is high severity.

## Required authorization and safety constraints

An authorized operator may inspect safe connection metadata and use approved controls. Never display secrets, discard outbox/reconciliation records, or label reported external state as verified.

## Preconditions and diagnosis

Record affected organization/project, correlation IDs, safe provider category, circuit state, queue state, and provider status independently of application retries.

## Containment and recovery procedure

Stop unnecessary retries; preserve outbox and reconciliation records; resolve the configuration or provider fault; close or reset the circuit only through approved behavior; resume bounded operations; then reconcile.

## Verification

Confirm safe connection health, bounded queue recovery, reconciliation result, and no duplicate external effect.

## Rollback or abort conditions

Abort when credentials, schema, provider ownership, or duplicate state is uncertain.

## Evidence to retain, escalation, and follow-up actions

Retain identifiers, safe errors, provider-status evidence, circuit transitions, reconciliation counts, and operator decisions. Escalate provider incidents and open follow-up work.
