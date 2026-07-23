# ADR-0004: Use Inertia and React for application UI

- Status: Accepted
- Date: 2026-07-18
- Owners: Human operator and project maintainers

## Context

The application requires a React interface while Laravel remains responsible for routing, sessions, authorization, validation, and domain behavior.

## Decision

Use Inertia.js 3, React, and TypeScript in the Laravel repository. Do not create a separate browser-facing frontend service for the MVP.

## Consequences

Page data and actions remain server-authoritative while the frontend provides interactive presentation.

## Review triggers

Review this decision when scale, security, reliability, provider capability, or
deployment evidence demonstrates that the existing decision no longer meets the
system requirements.
