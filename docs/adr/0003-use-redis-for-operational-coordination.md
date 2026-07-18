# ADR-0003: Use Redis for operational coordination

- Status: Accepted
- Date: 2026-07-18
- Owners: Human operator and project maintainers

## Context

Queues, cache, locks, leases, rate limits, and transient coordination require low-latency operational storage.

## Decision

Use Redis for operational and derived state. Durable business state remains in PostgreSQL.

## Consequences

Redis loss must not destroy authoritative workflow or evidence records.

## Review triggers

Review this decision when scale, security, reliability, provider capability, or
deployment evidence demonstrates that the existing decision no longer meets the
system requirements.
