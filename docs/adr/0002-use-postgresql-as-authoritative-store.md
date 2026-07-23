# ADR-0002: Use PostgreSQL as the authoritative store

- Status: Accepted
- Date: 2026-07-18
- Owners: Human operator and project maintainers

## Context

Workflow, approvals, execution, evidence, audit, and synchronization state require transactional consistency and relational constraints.

## Decision

PostgreSQL is authoritative for durable application state. Redis and external tools must not become the source of truth for workflow state.

## Consequences

Schema design and migrations must preserve transactional and recovery guarantees.

## Review triggers

Review this decision when scale, security, reliability, provider capability, or
deployment evidence demonstrates that the existing decision no longer meets the
system requirements.
