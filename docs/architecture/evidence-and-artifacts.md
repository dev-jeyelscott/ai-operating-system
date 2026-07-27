# Evidence and Artifacts

## Purpose

Artifacts preserve immutable references to outputs produced by execution attempts.
Evidence preserves immutable claims, observations, verifications, and rejections
about those artifacts.

These records support auditability and prevent a provider claim or simulated
result from being presented as implementation truth.

## Artifact model

Every artifact records:

- Project, execution, and execution-attempt lineage
- Artifact type and human-readable name
- Execution provider
- Object-storage or immutable external reference
- Optional media type, SHA-256 checksum, and byte size
- Simulation mode and seed when applicable
- Assumptions and confidence
- Whether external evidence is still required
- Actual state
- Redacted metadata
- Creation timestamp

Artifact content belongs in object storage or an immutable external system. Raw
prompts, credentials, secrets, and unredacted provider payloads must not be
stored in the artifact row.

## Evidence classifications

Evidence uses one of these immutable classifications:

- `assumption`
- `proposal`
- `simulated_output`
- `reported_evidence`
- `observed_evidence`
- `verified_evidence`
- `rejected_evidence`

A stronger classification creates a new evidence row. Existing records are not
updated in place.

## Evidence model

Every evidence record contains:

- Artifact reference
- Classification
- Evidence type and provider
- Immutable source reference
- Optional commit SHA
- One or more claims
- Observation and verification timestamps where applicable
- Verification method or rejection reason where applicable
- Optional expiration timestamp
- Confidence
- Redacted metadata
- Creation timestamp

## Non-deception rules

1. A simulation artifact must remain `unverified`.
2. A simulation artifact must declare that evidence is still required.
3. Simulation mode and seed must match the producing execution attempt.
4. A simulation artifact cannot receive `verified_evidence`.
5. Provider-reported success is `reported_evidence`, not verification.
6. Directly inspected external state is `observed_evidence` until the configured
   verification method succeeds.
7. Rejected evidence remains preserved for audit history.

## Data integrity

PostgreSQL enforces:

- Project, execution, and attempt lineage
- Provider and simulation provenance consistency
- Evidence classification lifecycle fields
- JSONB shapes for assumptions, claims, and metadata
- Confidence bounds
- Commit and checksum formats
- Append-only update and delete rejection

Eloquent rejects update and delete attempts before the database trigger applies
the same invariant.

## Deferred behavior

This ticket intentionally does not add:

- Artifact upload or download services
- Provider-result ingestion services
- Evidence verification adapters
- HTTP controllers or UI
- Artifact deduplication
- Evidence expiration jobs
- QA or merge-gate evaluation

Those behaviors should consume these persistence contracts in later tickets.
