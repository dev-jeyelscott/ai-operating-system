# Audit-event foundation

## Ownership

The Audit module owns the `audit_events` table and the append-only audit
persistence and timeline-query contracts.

Other modules may append events only through `RecordAuditEvent`. They must not
write directly to the Audit module table.

Other modules may read audit history only through `ListAuditTimeline` and the
`AuditTimelineQuery` application boundary. They must not construct cross-module
queries directly against `audit_events`.

## Invariants

1. Audit events are append-only.
2. Privileged state changes and their audit events commit atomically.
3. Audit history survives deletion of referenced application records.
4. Every event belongs to an organization.
5. Project events include both organization and project identifiers.
6. Actor and subject identifiers are stored as immutable historical values.
7. Metadata is explicitly allowlisted, JSON serializable, bounded, and
   centrally redacted.
8. Passwords, tokens, credentials, request bodies, and document contents must
   never be included.
9. Stable ordering uses `sequence`, then `occurred_at`.
10. Sequence values are monotonic but may contain gaps after rolled-back
    transactions.
11. Every timeline query requires an explicit organization identifier.
12. Optional filters may narrow tenant-scoped results but may never remove the
    organization boundary.
13. Audit timeline pagination uses opaque cursors rather than numeric offsets.
14. Timeline queries never update, delete, or reconcile audit records.

## Document lifecycle events

Phase 3 document lifecycle transitions use `audit_events` as the authoritative
append-only event stream. Events include organization and project identifiers,
an authenticated user or system-worker actor, correlation/causation and
optional execution identifiers, schema version, a tenant-scoped deduplication
key, safe document identifiers, and bounded transition metadata.

Document bodies, parsed content, analysis summaries, conflicts, gaps, prompts,
storage paths, original filenames, credentials, secrets, and raw provider
failure messages are prohibited from audit metadata. State mutation and
mandatory audit evidence are written in the same database transaction; audit
failure rolls back the authoritative transition.

## Audit timeline query model

AIOS-060 provides a read-only application query model for reconstructing
authoritative audit history.

The query supports:

- Required organization scope
- Optional project scope
- Optional execution identifier
- Optional correlation identifier
- Optional causation identifier
- Optional event type
- Optional subject type
- Optional subject identifier
- Oldest-first and newest-first ordering
- Bounded cursor pagination

The query always applies ordering in this order:

```text
sequence
occurred_at
```

The same direction is applied to both fields. The unique sequence remains the
authoritative ordering key, while occurred_at remains an explicit secondary
timeline attribute required by the application contract.

The query returns persisted authoritative audit events. It does not create a
separate mutable projection, infer missing events, or combine simulated claims
with verified evidence.

Calling HTTP or application boundaries must authorize the active organization
and project before constructing AuditTimelineCriteria. The query model itself
still applies mandatory tenant filtering as a defense-in-depth control.

### Pagination

Cursor pagination is used because audit history is append-only and may grow
while a client is navigating it.

Cursor values are opaque Laravel pagination tokens. Clients must send the
returned cursor unchanged and must not derive business meaning from it.

The maximum supported page size is 100 events.

### Database indexes

AIOS-060 does not introduce a new database migration.

The current schema already provides:

- (organization_id, sequence)
- (project_id, sequence)
- execution_id
- correlation_id
- causation_id
- event_type
- occurred_at

These indexes are sufficient for the MVP query contract. Additional compound
indexes must be based on measured production query plans and are deferred to
the high-volume query optimization work in AIOS-144.

### Deferred capabilities

The following capabilities remain outside AIOS-060:

- HTTP and Inertia audit timeline screens
- Execution timeline presentation
- Timeline exports
- Retention policies
- Real-time timeline publication
- Projection checkpointing
- High-volume query-plan optimization
