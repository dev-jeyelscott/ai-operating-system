# ADR-0005: Use provider-neutral simulation-first execution

- Status: Accepted
- Date: 2026-07-18
- Owners: Human operator and project maintainers

## Context

The MVP must prove orchestration, approvals, audit, and UI without treating assumed provider output as verified implementation evidence.

## Decision

Simulation is a first-class provider behind shared provider contracts. Provider-specific identifiers, flags, and response formats remain inside adapters.

## Consequences

Simulation can advance planning and approval preparation but cannot prove implementation, tests, CI, merge, or deployment.

## Review triggers

Review this decision when scale, security, reliability, provider capability, or
deployment evidence demonstrates that the existing decision no longer meets the
system requirements.
