# ADR-0006: Use a transactional outbox for consequential events

- Status: Accepted
- Date: 2026-07-18
- Owners: Human operator and project maintainers

## Context

Database changes and externally visible side effects must not diverge during crashes or retries.

## Decision

Persist state changes and outbox records in the same PostgreSQL transaction. Dispatch asynchronously with idempotent consumers.

## Consequences

External delivery becomes eventually consistent but recoverable, replayable, and duplicate-safe.

## Review triggers

Review this decision when scale, security, reliability, provider capability, or
deployment evidence demonstrates that the existing decision no longer meets the
system requirements.
