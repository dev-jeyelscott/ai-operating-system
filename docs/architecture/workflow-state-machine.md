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
3. Loads the bound immutable definition and owning project.
4. Confirms the current state belongs to the definition.
5. Rejects transitions from terminal states.
6. Resolves the requested transition by its stable name.
7. Confirms the transition starts at the persisted current state.
8. Evaluates its deterministic guard when one is declared.
9. Appends a `workflow_transitions` record.
10. Updates the instance's materialized current state and sequence.
11. Appends `workflow.transitioned` to the transactional outbox.
12. Records the matching authoritative audit event.
13. Commits all writes atomically.

An exception from history persistence, state persistence, outbox persistence, or
audit persistence rolls back the complete transition.

## Transition context

Every transition receives an immutable context containing:

- Actor type
- Actor identifier
- Correlation identifier
- Causation identifier
- Execution identifier
- Deterministic guard context

New callers should pass guard inputs through `WorkflowTransitionContext`.

Raw guard context is evaluated in memory and is not automatically persisted.
The event and audit records contain the stable guard identifier only. This
prevents arbitrary guard inputs from entering durable event or audit metadata.

When no explicit context is supplied by a legacy caller, the transition service
uses the stable system actor `workflow-transition-service`.

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

The transition table intentionally retains the compact transition facts needed
for deterministic state reconstruction:

- Workflow instance
- Sequence
- Transition name
- From state
- To state
- Guard identifier
- Creation time

Actor identity, correlation, causation, execution identity, schema version, and
event payload are retained by the transactional outbox and append-only audit
store. These records allow every successfully committed workflow transition to
be reconstructed without duplicating trace metadata in
`workflow_transitions`.

## Failure behavior

A failed outbox append or failed audit append prevents the transition from
committing.

After failure:

- No workflow transition row exists.
- The workflow instance remains in its previous state.
- The transition sequence is unchanged.
- No matching outbox event exists.
- No matching audit event exists.

## Exclusions

This transition operation does not:

- Publish the outbox message synchronously.
- Execute provider work.
- Store raw guard input in transition history.
- Modify immutable workflow definitions.
- Bypass authorization or application command policy.
- Add query-specific columns to `workflow_transitions`.
