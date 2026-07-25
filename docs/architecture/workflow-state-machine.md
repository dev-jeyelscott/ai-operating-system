# Workflow Instance and Transition State Machine

## Decision

`workflow_instances` stores the current materialized state of one workflow
execution. Every instance permanently references one immutable
`workflow_definitions` row through `workflow_definition_id`.

Publishing a newer definition version never changes an existing instance.

## Transition commitment

A transition is committed only after the application:

1. Opens a database transaction.
2. Locks the workflow-instance row with `FOR UPDATE`.
3. Loads the bound immutable definition.
4. Confirms the current state belongs to the definition.
5. Rejects transitions from terminal states.
6. Resolves the requested transition by its stable name.
7. Confirms the transition starts at the persisted current state.
8. Evaluates its deterministic guard when one is declared.
9. Appends a `workflow_transitions` record.
10. Updates the instance's current state and sequence.
11. Commits both writes atomically.

Any exception rolls back both the state update and transition record.

## Guard policy

Definition manifests store stable guard identifiers only. They never store PHP
callbacks, closures, provider prompts, arbitrary expressions, or executable
code.

Guarded transitions fail closed when no approved deterministic evaluator exists.
The default evaluator therefore rejects every guarded transition.

Future policy tickets may replace the evaluator binding with registered,
deterministic guard implementations without changing the state-machine
contract.

## Concurrency

`TransitionWorkflowInstance` reloads the instance under `lockForUpdate()` inside
the application transaction.

Competing workers therefore cannot validate and commit against the same stale
state. The first committed transition changes the state and sequence; the next
worker must revalidate against that committed state.

The unique `(workflow_instance_id, sequence)` constraint provides an additional
database boundary against duplicate transition ordering.

## History

`workflow_transitions` is append-only at both the Eloquent and PostgreSQL
boundaries. Rejected transition attempts are not stored as successful
transitions.

AIOS-049 and later audit/event work will record attempted commands, actors,
correlation identifiers, causation identifiers, and event schema versions.

## Exclusions

AIOS-047 does not implement:

- Application command bus
- Domain event envelope
- Transactional outbox
- Execution or execution-attempt models
- Approvals
- Retry, timeout, or cancellation policy
- General idempotency service
- StartProject orchestration
- HTTP controllers or frontend workflow controls
