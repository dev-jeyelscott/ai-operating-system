# Notification Persistence

## Purpose

AIOS notification persistence converts authoritative domain events into
sanitized user-facing notification events and assigns those events to explicit
organization members.

AIOS-059 implements only the persistence model and deduplication invariants.

## Tables

### `notification_events`

Stores one user-facing notification occurrence derived from one domain event.

Important fields:

- `source_event_id` — originating domain-event identifier
- `organization_id` — mandatory tenant ownership
- `project_id` — optional project context
- `event_name` — canonical dotted domain-event name
- `correlation_id` — request and workflow trace
- `execution_id` — optional execution trace
- `title`, `message`, `action_url` — sanitized presentation content
- `data` — sanitized JSON object for later rendering
- `occurred_at` — authoritative domain-event occurrence time

`source_event_id` is unique. Replaying the same domain event must not create
another notification event.

### `notification_recipients`

Assigns one notification event to one authenticated AIOS user.

The combination of:

```text
notification_event_id + recipient_user_id
```

is unique.

This is the final race-condition guard against duplicate notification
assignment during event replay or concurrent consumer execution.

### Tenant isolation

A composite foreign key guarantees that a recipient row has the same
organization as its parent notification event.

A PostgreSQL trigger verifies that the recipient user has an organization
membership when the assignment is inserted.

Membership removal does not delete historical notification assignments and is
not blocked by a permanent recipient-to-membership foreign key.

### Content safety

Notification persistence must never contain:

- Provider credentials
- Raw prompts
- Unredacted document content
- Complete external provider responses
- Secrets
- Arbitrary domain-event payload copies

The notification materializer must construct an explicit sanitized title,
message, optional action URL, and safe data object.

### Deferred scope

AIOS-059 does not implement:

- In-app inbox queries
- Delivery status
- Read or unread status
- Notification bell UI
- Broadcasting or Reverb events
- Email delivery
- Slack delivery
- Mobile push delivery
- User notification preferences
- Retry or dead-letter handling for delivery

AIOS-115 will extend the recipient model with in-app delivery and read-state
behavior without changing the event-and-recipient deduplication identity.
