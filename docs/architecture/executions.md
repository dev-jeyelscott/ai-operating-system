# Executions and Execution Attempts

## Purpose

The execution model records one logical unit of workflow work and every
provider attempt made to perform that work.

An execution is the current aggregate summary. An execution attempt is the
historical snapshot of one provider invocation.

## Execution

An execution records:

- Project ownership
- Optional coordinating workflow instance
- Capability
- Logical role
- Current lifecycle status
- Requested reasoning level
- Attempt count
- Correlation identifier
- Project-scoped idempotency key
- Start and finish timestamps

Execution identifiers use ULIDs because they are exposed through domain-event,
audit, artifact, and observability contracts.

## Execution attempt

Each attempt records:

- Ordered attempt number
- Attempt status
- Execution provider
- Provider model identifier
- Requested reasoning level
- Effective reasoning level
- Reasoning resolution source
- Reasoning escalation reason
- Simulation mode and seed
- Reported state
- Observed state
- Actual state
- Confidence
- Estimated cost
- Actual cost
- Currency
- Redacted error code and message
- Start and finish timestamps

A retry or provider fallback must create a new attempt. It must not replace the
provider or reasoning snapshot of an existing attempt.

## Invariants

1. Execution IDs are ULIDs.
2. Idempotency keys are unique within a project.
3. A workflow instance associated with an execution must belong to the same
   project.
4. Attempt numbers begin at one and are unique within an execution.
5. Execution identity and request context are immutable after creation.
6. Attempt provider and reasoning context are immutable after creation.
7. Executions and attempts cannot be deleted through Eloquent.
8. Confidence is between zero and one.
9. Costs cannot be negative.
10. Finished timestamps cannot precede started timestamps.
11. Error fields contain redacted content only.
12. Simulation results must not use a verified actual state.
13. Artifacts must preserve the project, execution, attempt, provider, and
    simulation provenance of the attempt that created them.
14. Evidence must use the classification and non-deception rules documented in
    `docs/architecture/evidence-and-artifacts.md`.

## Ownership

The Execution module owns:

- `executions`
- `execution_attempts`
- Execution lifecycle enums
- Execution persistence models

The evidence persistence contract owns:

- `artifacts`
- `evidence`
- Evidence classification enums
- Artifact and evidence persistence models

Other modules must use future application services rather than manipulating
these tables directly.

## Deferred behavior

The following behavior is intentionally deferred:

- Approval handling
- Reasoning policy resolution
- Attempt creation and lifecycle orchestration
- Retry scheduling and backoff
- Timeout enforcement
- Cancellation
- Dead-letter handling
- Provider dispatch
