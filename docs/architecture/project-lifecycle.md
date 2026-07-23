# Project Aggregate and Lifecycle

## Ownership

The Projects module owns:

- project identity and organization ownership;
- core project metadata;
- project type;
- project lifecycle vocabulary;
- allowed lifecycle transitions.

Project configuration, documents, workflows, roadmaps, tickets, executions,
and audit records are separate models owned by their respective modules.

## Aggregate root

`App\Models\Project` is the persistence aggregate root.

Pure lifecycle rules remain in:

- `App\Domain\Projects\ProjectStatus`
- `App\Domain\Projects\ProjectLifecycle`
- `App\Domain\Projects\Exceptions\InvalidProjectStatusTransition`

The model delegates lifecycle validation to the domain service before
persisting a state change.

## States

- `draft`
- `configuring`
- `documents_pending`
- `ready_for_planning`
- `planning`
- `awaiting_roadmap_approval`
- `ready_for_development`
- `active`
- `paused`
- `blocked`
- `completed`
- `cancelled`

## Transition rules

| Current state | Allowed next states |
|---|---|
| Draft | Configuring, Cancelled |
| Configuring | Documents Pending, Blocked, Cancelled |
| Documents Pending | Configuring, Ready for Planning, Blocked, Cancelled |
| Ready for Planning | Configuring, Planning, Blocked, Cancelled |
| Planning | Awaiting Roadmap Approval, Blocked, Cancelled |
| Awaiting Roadmap Approval | Planning, Ready for Development, Blocked, Cancelled |
| Ready for Development | Planning, Active, Blocked, Cancelled |
| Active | Paused, Blocked, Completed, Cancelled |
| Paused | Active, Blocked, Cancelled |
| Blocked | Configuring, Cancelled |
| Completed | None |
| Cancelled | None |

## Invariants

1. New projects begin in `draft`.
2. Status is not mass assignable.
3. Status changes use `Project::transitionTo()`.
4. Transitions execute under a database transaction and row lock.
5. Invalid transitions throw `InvalidProjectStatusTransition`.
6. `completed` and `cancelled` are terminal.
7. Unknown project statuses and types are rejected by PostgreSQL constraints.
8. An organization cannot be deleted while projects still reference it.
9. Returning from `blocked` to `configuring` requires all readiness conditions
   to be evaluated again.
10. Project archive and restore are administrative operations and are not
    represented as project workflow states.

## Assumption

The approved specification defines the project states but does not provide a
complete transition matrix. This matrix is a conservative implementation
assumption derived from the documented project onboarding, planning,
development, pause, block, completion, and cancellation flows.

Any future change to this matrix requires:

- an approved documentation or ticket change;
- an update to `ProjectLifecycle`;
- unit-test updates;
- assessment of persisted projects affected by the change.

## Deferred tickets

- AIOS-016: project CRUD, archive, and restore
- AIOS-017: mandatory tenant-safe query and repository patterns
- AIOS-018: append-only transition audit events
- AIOS-021: versioned project configuration