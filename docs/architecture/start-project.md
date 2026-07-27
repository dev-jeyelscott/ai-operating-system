# Idempotent StartProject Command

## Purpose

`StartProject` creates the deterministic entry point into Layer 1 project
planning.

It does not invoke an AI provider or generate a roadmap. Those operations are
owned by later Layer 1 planning tickets.

## Preconditions

The command requires:

- An authorized organization owner or administrator
- Project status `ready_for_planning`
- Complete current project configuration
- A current immutable configuration version
- Approved required documents
- A valid connected Notion integration
- Completed setup review
- A non-zero or deliberately unbounded budget
- No active project execution
- A registered `project_delivery` workflow definition

## Idempotency layers

The command has three duplicate-prevention layers:

1. The command bus uses an atomic cache lock.
2. The idempotency service durably stores the encrypted command result.
3. The executions table uniquely stores the SHA-256 hash of the caller key
   within the project.

The third layer closes the crash window where authoritative project state
committed but the durable idempotency result had not yet been finalized.

## Context identity

The command stores a non-secret fingerprint containing:

- Immutable project configuration version ID
- Configuration revision
- Approved document IDs
- Approved document-version IDs
- Document version numbers
- SHA-256 document checksums

The handler rebuilds this fingerprint while holding the project row lock. A
stale request cannot start another context silently.

## Atomic effects

The following database changes occur within the same transaction:

- Create or reuse project context snapshot
- Create workflow instance
- Create queued planning execution
- Transition project to `planning`
- Append domain events to the outbox
- Append audit events
- Create the requester notification and recipient assignment

An exception rolls back all authoritative database changes.

Content-addressed document artifacts may already exist in object storage after
a failed transaction. They are safe immutable objects and can be reconciled or
garbage-collected later.

## Events

The command may append:

- `project.context_snapshotted`
- `project.start_requested`

The snapshot event is emitted only when a new immutable snapshot is created.

## Deferred behavior

This ticket does not implement:

- Planning provider dispatch
- Execution attempts
- Roadmap generation
- Approval requests
- Notion ticket publication
- HTTP or Inertia interfaces
- Project timeline UI
