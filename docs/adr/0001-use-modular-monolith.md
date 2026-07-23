# ADR-0001: Use a Laravel modular monolith

- Status: Accepted
- Date: 2026-07-18
- Owners: Human operator and project maintainers

## Context

The system requires transactional workflow, approvals, audit, evidence, and integration behavior without proven distributed-system scale.

## Decision

Use one Laravel application and repository with explicit Domain, Application, Infrastructure, and delivery boundaries. Do not introduce microservices without an approved replacement ADR.

## Consequences

Transactions and operational deployment remain simple. Module boundaries must be enforced through conventions and architecture tests.

## Review triggers

Review this decision when scale, security, reliability, provider capability, or
deployment evidence demonstrates that the existing decision no longer meets the
system requirements.
