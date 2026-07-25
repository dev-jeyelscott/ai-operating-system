# Audit-event foundation

## Ownership

The Audit module owns the `audit_events` table and the append-only audit
persistence contract.

Other modules may append events only through `RecordAuditEvent`. They must not
write directly to the Audit module table.

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

## Deferred capabilities

Audit timeline projections, exports, retention policy, transactional outbox
delivery, and real-time publication are implemented by later tickets.
