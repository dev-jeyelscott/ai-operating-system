# AI Operating System architecture

## Purpose and scope

AI Operating System is a three-layer software-delivery workflow: Layer 1
creates and obtains approval for a roadmap, Layer 2 simulates engineering work
for eligible tickets, and independent Layer 3 produces QA and merge advice.
The MVP is simulation-first. Workflow state, approvals, Notion publication,
audits, and operational controls are real; repository writes, pull requests,
CI verification, merges, and deployments are simulated or explicitly excluded.

## System context

Human users operate the AI Operating System, which uses PostgreSQL for durable
authoritative state, Redis for queues, cache, locks, and transient coordination,
object storage for artifact content, Notion for task-level publication, Horizon
for workers, Reverb for real-time delivery, and GitHub Actions for repository
quality gates. Future execution providers sit behind provider-neutral contracts.

## Architectural style

The accepted ADRs establish a Laravel modular monolith, Inertia/React UI,
transactional outbox, provider-neutral simulation, and transport-neutral
real-time events. See the [ADRs](../adr/) and
[module boundaries](module-boundaries.md).

## Layers and module map

`app/Domain` holds deterministic rules and contracts; `app/Application`
orchestrates use cases; `app/Infrastructure` implements storage and provider
adapters; `app/Http` and `app/Console` are delivery entry points. `resources/js`
contains the accessible dashboard and office; `database` contains schema and
seed data; `tests` proves contracts.

| Module | Owns | Public boundary and events | External dependencies / tables | Forbidden coupling |
|---|---|---|---|---|
| Identity | users and memberships | identity application services | identity tables | Other modules do not bypass organization scope |
| Projects | project lifecycle and configuration | project commands and lifecycle events | project tables | No direct writes by other modules |
| Documents | document versions, review, snapshots | document services and events | document tables, storage | No raw content or cross-module mutation |
| Requirements and Roadmaps | requirements, phases, milestones, traceability | planning services and events | planning tables | No direct task-state mutation |
| Tasks | tickets, dependencies, leases | selection and lease services | task tables | No duplicate ticket claims |
| Workflows and Approvals | definitions, transitions, approval decisions | command and transition events | workflow tables | No unguarded transition |
| Executions and Providers | attempts, simulation contracts, artifacts | execution services and events | execution tables, provider adapters | Provider details never enter domain contracts |
| Integrations | credentials and external mappings | connection, publication, reconciliation | integration tables, Notion | Credentials stay encrypted and scoped |
| Evidence and Audit | immutable claims and audit timeline | evidence and audit events | evidence, artifact, audit tables | No mutation or deletion |
| Operations and notifications | read models, recovery, office projections | projection and notification events | projection tables, Redis/Reverb | Derived state never becomes authoritative |

The application-layer modules map the implementation more precisely:

| Application modules | Scope |
|---|---|
| `Approvals`, `Audit`, `Documents`, `Identity`, `Projects`, `Planning`, `Tickets`, `Workflows` | Human-controlled delivery lifecycle and tenant-scoped state |
| `Development`, `Executions`, `Orchestration`, `QualityAssurance`, `Simulation` | Layer 2/3 simulation orchestration and assessment |
| `Events`, `Notifications`, `Operations`, `Synchronization` | Outbox, projections, recovery, and external-state synchronization |
| `Integrations`, `ProviderRouting`, `ProjectIntelligence` | Notion and provider-neutral integration contracts |
| `Policies`, `Security`, `Shared` | Deterministic policy, security boundaries, and shared command contracts |

Migration comments define precise table ownership. Cross-module work uses a
public application service, contract, or domain event, never a raw table write.

## Request and command flow

```text
HTTP / Console / Job
  -> authorization and validation
  -> application command and deterministic policy
  -> PostgreSQL transaction and audit record
  -> transactional-outbox event
  -> asynchronous consumer and read-model refresh
  -> Reverb projection update
  -> accessible dashboard and 3D office
```

## Workflow state model

Project states progress from draft through configuration, planning, approved
development, active, terminal, or blocked states. Workflow instances use
immutable definitions and guarded, append-only transitions. Tickets move only
through their owned state model; executions retain attempts, bounded retry,
timeout, cancellation, and lease history. Approval decisions are explicit and
human controlled. Merge decisions advise a human and never perform a merge.

Transitions require an authorized initiating command, deterministic guards,
transactional evidence, an outbox event, and audit correlation identifiers.
Row locks, idempotency keys, lease expiration, bounded retries, dead-letter
replay, and projection rebuild provide recovery. Detailed state contracts are
in [project lifecycle](project-lifecycle.md),
[workflow state machine](workflow-state-machine.md),
[executions](executions.md), and [dead-letter recovery](dead-letter-recovery.md).

## Data architecture

Every durable record is organization and project scoped. Snapshots,
configuration versions, document checksums, roadmap/task dependencies,
execution attempts, artifacts, evidence classifications, audit identifiers,
Notion mappings, and office projections preserve lineage. Retention duration
remains an approved-policy decision; this documentation does not invent one.

## Integration architecture

Notion credentials are encrypted, connection-tested, and never exposed in
responses or audit logs. Publication uses stable external keys, idempotent
upsert, reconciliation, conflict handling, circuit breaking, and backpressure.
Object storage holds artifact content. Reverb transports projections only. A
future GitHub provider must preserve simulation and evidence contracts.

## Security architecture

The [threat model](../security/threat-model.md) is authoritative for
authorization, tenant isolation, redaction, upload quarantine, provider-result
validation, prompt-injection resistance, protected branches, rate limits, and
supply-chain controls. Evidence classifications prevent simulated output from
being presented as verified.

## Reliability architecture

Reliability uses transactions, outbox dispatch, consumer deduplication,
backoff, deadlines, leases, dead letters, manual replay, reconciliation, and
projection rebuild. Backups and restore rehearsal are documented in
[operations](../operations/backup-restore-disaster-recovery.md).

## Architecture validation

```bash
./vendor/bin/sail artisan test tests/Architecture
./vendor/bin/sail artisan test --testsuite=Concurrency --fail-on-skipped --fail-on-warning
./vendor/bin/sail composer types:check
```
