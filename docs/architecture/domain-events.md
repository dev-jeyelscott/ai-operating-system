# Domain-event envelope and schema versioning

## Ownership

The shared Events domain owns the canonical domain-event payload contract,
actor identity, envelope, and schema-version rules.

Business modules define concrete event payload classes that implement
`App\Domain\Events\DomainEvent`.

The Events domain does not own workflow state, audit persistence, queue
dispatching, external integration, or read-model projections.

## Purpose

Domain events describe authoritative business facts that have already been
accepted by deterministic application rules.

Examples include:

- `project.start_requested`
- `workflow.transition_committed`
- `workflow.blocked`
- `approval.requested`
- `approval.granted`

Events are immutable facts. They are not commands and must not instruct a
consumer to bypass authorization, workflow guards, approvals, or policy.

## Canonical envelope

Every domain-event envelope contains:

- `event_id`
- `event_name`
- `aggregate_type`
- `aggregate_id`
- `organization_id`
- `project_id`
- `actor`
- `provider`
- `occurred_at`
- `correlation_id`
- `causation_id`
- `execution_id`
- `schema_version`
- `payload`

`event_id` is a ULID.

`correlation_id` identifies the complete distributed operation. A root event
uses its own event ID when no upstream correlation ID exists.

`causation_id` identifies the command or event that directly caused the event.

`execution_id` is nullable because not every deterministic or human operation
belongs to a provider execution.

`provider` is nullable because human and application-owned operations may not
use an execution provider.

## Event naming

Event names use lowercase dotted notation:

```text
<domain>.<past-tense-fact>
```

### Examples

```txt
project.created
project.start_requested
workflow.transition_committed
approval.granted
```

Names describe facts that occurred. Do not use imperative command names such as
`workflow.transition` or `project.start`.

Once published, an event name must not be repurposed for a different fact.

### Schema-version policy

Each event payload starts at schema version 1.

The schema version belongs to the concrete payload contract, not to the
consumer or transport.

A new schema version is required when a change:

- removes a field;
- renames a field;
- changes a field type;
- changes the meaning of an existing field;
- makes an optional field required;
- changes identifier or timestamp semantics.

A version increment is normally unnecessary when adding an optional field
whose absence preserves the previous meaning.

Published versions remain immutable. Do not modify an old payload class to
represent a breaking contract.

Consumers must select behavior using both event_name and schema_version.

Unknown future versions must fail safely and must not advance workflow state.

### Payload safety

Payloads contain stable identifiers and safe business facts only.

Do not include:

- Eloquent models;
- passwords, tokens, credentials, or secrets;
- document bodies or parsed document content;
- prompts or raw provider requests;
- raw exception messages;
- storage paths or temporary signed URLs;
- mutable objects;
- closures or resources.

Payloads must use string keys and must be JSON serializable.

### Relationship to audit events

Domain events and audit events have different responsibilities.

Domain events coordinate modules, outbox consumers, projections, and future
integrations.

Audit events preserve an append-only human and operational history.

AIOS-049 does not replace the existing Audit module or its `audit_events`
table.

Where the same business fact requires both domain delivery and audit evidence,
the owning application transaction will write both through their approved
contracts.

### Delivery boundary

AIOS-049 defines and validates the domain-event contract only.

AIOS-050 will persist domain-event envelopes through a transactional outbox in
the same database transaction as authoritative state changes.

AIOS-051 will dispatch outbox records and implement deduplicated consumers.

Do not dispatch these envelopes directly through Laravel events before the
transactional outbox exists.
