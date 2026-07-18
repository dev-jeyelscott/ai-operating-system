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

## Deferred capabilities

Audit timeline queries, projections, exports, retention, event schema
versioning, causation IDs, execution IDs, and outbox delivery are implemented
by later tickets.
