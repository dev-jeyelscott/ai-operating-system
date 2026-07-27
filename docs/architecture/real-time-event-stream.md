# Real-time Event-stream Abstraction

## Status

Implemented by AIOS-061.

## Purpose

The real-time event stream delivers sanitized projection messages to connected
dashboard and 3D-office clients without coupling application consumers to
Laravel Reverb, WebSockets, SSE, or another delivery technology.

The real-time stream is not an authoritative workflow store. PostgreSQL,
workflow state, projection read models, and the transactional outbox remain the
source of truth.

## Canonical flow

```text
Committed domain event
    |
    v
AIOS-051 deduplicated domain-event consumer
    |
    v
Projection builder updates durable read model
    |
    v
Projection consumer constructs RealTimeStreamMessage
    |
    v
RealTimeEventStream
    |
    v
Configured infrastructure adapter
    |
    v
Private organization or project channel
```

### Application contract

Application and projection code depends on:

```txt
App\Application\Events\Contracts\RealTimeEventStream
```

Messages use:

```txt
App\Application\Events\Data\RealTimeStreamMessage
```

Projection consumers must not depend directly on:

- Laravel broadcast events
- Laravel Reverb
- Pusher protocol details
- WebSocket connections
- Server-Sent Events
- Browser connection state

### Current adapters

#### Broadcast adapter

```txt
App\Infrastructure\Events\LaravelBroadcastRealTimeEventStream
```

The broadcast adapter emits a synchronous Laravel broadcast event through the
configured broadcaster connection. The local and production defaults use
Laravel Reverb.

#### Null adapter

```txt
App\Infrastructure\Events\NullRealTimeEventStream
```

The null adapter disables external live delivery while preserving the
application contract. It must not be interpreted as verified delivery.

#### Driver configuration

```dotenv
EVENT_STREAM_DRIVER=broadcast
EVENT_STREAM_BROADCAST_CONNECTION=reverb
```

Supported drivers:

- broadcast
- null

A future SSE adapter must implement `RealTimeEventStream` and be selected in the
service-provider composition root. Existing projection consumers must remain
unchanged.

### Channel model

Organization-level messages use:

```txt
private-organizations.{organizationId}.stream
```

Project-level messages use:

```txt
private-organizations.{organizationId}.projects.{projectId}.stream
```

Organization channels require an active organization membership.

Project channels require:

1. A project belonging to the organization represented in the channel name.
2. Authorization through `ProjectPolicy::view`.

### Message contract

Every real-time message contains:

- Stable event ID
- Stable dotted event name
- Organization ID
- Optional project ID
- UTC occurrence timestamp
- Correlation ID
- Optional execution ID
- Positive schema version
- Explicit sanitized data object

The event ID allows clients to ignore a duplicate delivery.

### Content safety

Real-time messages must never copy arbitrary domain-event envelopes.

They must not contain:

- Credentials or secrets
- Raw prompts
- Uploaded or parsed document bodies
- Raw provider requests or responses
- Eloquent models
- Temporary signed URLs
- Raw exception messages
- Unbounded logs
- Mutable objects or resources

`RealTimeStreamMessage` accepts only JSON-safe scalar, list, and object values
and rejects arbitrary PHP objects before broadcasting.

### Delivery and recovery semantics

The stream is a low-latency notification mechanism, not a durable replay log.

Transport delivery may be repeated when an upstream consumer is retried.
Clients should use `event_id` for local duplicate suppression.

After initial load, refresh, dropped connection, or reconnect, the client must
retrieve the current durable read model from the application before applying
new live messages.

A real-time message must never perform or authorize a workflow transition.
Client actions continue to call authorized application commands.

### Deferred scope

AIOS-061 does not implement:

- Office projection builders
- Operations read models
- Dashboard subscriptions
- React hooks for project streams
- 3D-office subscriptions
- Reconnection reconciliation UI
- Notification bell delivery
- SSE HTTP endpoints
- Event retention or replay APIs

Those features are implemented by their later roadmap tickets using this
contract.
