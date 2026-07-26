# Policy Decisions and Reasoning Resolution

## Purpose

AIOS-054 adds deterministic reasoning resolution and an append-only policy
decision record. It does not authorize workflow transitions, execute providers,
or replace evidence requirements.

## Resolution inputs

The resolver accepts credential-free typed inputs:

1. Explicit approved ticket reasoning
2. Policy-required minimum
3. Task-type default
4. Agent-role default
5. Project default
6. System fallback (`medium`)
7. Mandatory escalation reasons

The policy minimum is applied as a floor. It may raise a weaker selected value,
but it cannot lower a stronger ticket, task, role, or project value.

## Mandatory escalation

The effective level is always `high` for:

- Security
- Authorization
- Privacy
- Money
- Critical business data
- Destructive changes
- Architecture conflicts
- Non-deterministic failures
- Production reliability
- Final QA
- Merge decisions

Mandatory reasons are stable enum values. Arbitrary prompt or document text is
not persisted in the policy decision.

## Persistence

`policy_decisions` stores:

- Project and execution ownership
- Decision type
- Resolver policy version
- Requested reasoning and its source
- Effective reasoning and its source
- Safe escalation explanation and stable reason codes
- Credential-free input snapshot
- SHA-256 input fingerprint
- Creation timestamp

One execution may have one `reasoning_resolution` decision. Replaying the same
inputs returns the existing row. Replaying different inputs raises a conflict.

## Immutability and tenant safety

- Eloquent rejects update and delete operations.
- PostgreSQL rejects update and delete operations through a trigger.
- A composite foreign key guarantees that the decision and execution belong to
  the same project.
- Project deletion is restricted while policy history exists.

## Execution-attempt integration

Future attempt creation copies these fields from the persisted decision:

- `requested_reasoning_level`
- `effective_reasoning_level`
- `reasoning_resolution_source`
- `reasoning_escalation_reason`

Retries create new execution attempts but do not rewrite the policy decision or
an earlier attempt snapshot.

## Versioning rule

Increment `ReasoningResolver::POLICY_VERSION` whenever precedence, escalation,
or output semantics change. Existing policy decisions remain immutable.
