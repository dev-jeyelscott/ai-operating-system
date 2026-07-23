# ADR-0007: Abstract the real-time transport

- Status: Accepted
- Date: 2026-07-18
- Owners: Human operator and project maintainers

## Context

The UI requires live execution updates, but workflow truth must not depend on Reverb or a browser connection.

## Decision

Expose provider-neutral application events and projections. Use Laravel Reverb initially behind a replaceable transport boundary.

## Consequences

Clients reconstruct state from durable read models and use real-time delivery only as an optimization.

## Review triggers

Review this decision when scale, security, reliability, provider capability, or
deployment evidence demonstrates that the existing decision no longer meets the
system requirements.
