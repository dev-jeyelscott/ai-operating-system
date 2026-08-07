# AI Operating System operations guide

## Runtime topology

The runtime comprises Laravel HTTP, PostgreSQL, Redis, Horizon workers, the
Laravel scheduler, Reverb, and S3-compatible storage. Vite and Mailpit are
development-only. The MVP execution provider is simulation in every environment.

## Environment matrix

| Component | Local | CI | Staging | Production |
|---|---|---|---|---|
| Database | Sail PostgreSQL | PostgreSQL service | Managed PostgreSQL | Managed PostgreSQL |
| Redis | Sail Redis | Redis service | Managed Redis | Managed Redis |
| Storage | MinIO | Local/test disk | S3 staging bucket | S3 production bucket |
| Mail | Mailpit | Array | Safe test transport | Approved provider |
| Broadcast | Reverb | Controlled Reverb or log | Reverb | Reverb |

## Required processes

Start required local processes with `./vendor/bin/sail artisan horizon`,
`./vendor/bin/sail artisan schedule:work`, and
`./vendor/bin/sail artisan reverb:start`.

## Health and readiness

Check readiness with:

```bash
curl -fsS http://localhost/up
curl -fsS http://localhost/health
curl -fsS http://localhost/ready
./vendor/bin/sail artisan app:check
```

## Queue and scheduler operations

Monitor Horizon status, failed jobs, scheduled outbox dispatch, expired
executions and leases (`executions:recover`), approval expiration, and office
projection refresh. Restart long-running workers cleanly after deployment so
they load the new release.

## Observability

Use structured JSON logs and retain request, correlation, causation, workflow,
execution, and attempt IDs. Track queue latency, retries, dead-letter age,
stuck leases, workflow duration/failure rate, evidence completeness, projection
lag, 3D rendering telemetry, and security-sign-off status.

## Deployment and promotion

Promote feature branches to `develop` through CI and human review; validate in
staging; complete the Definition of Done; then use a human-approved
`develop`-to-`main` PR. Tag the merged `main` commit and publish a prerelease.

## Migration policy

Use expand-and-contract migrations. Before production migration, back up,
review `migrate --pretend`, rehearse restore and migration in staging, and run
`migrate --force` only during a controlled deployment. Do not automatically run
`migrate:rollback` unless it is proven reversible and data-safe.

## Rollback

Rollback separately: application release, migration recovery, database restore,
object-version restoration, queue/workflow reconciliation, and projection
rebuild. See [backup and disaster recovery](backup-restore-disaster-recovery.md)
and the [runbooks](../runbooks/README.md).

## Backup and recovery

Use PostgreSQL custom-format backups, verify checksums and archive contents,
restore first into an isolated database, and retain S3 object versions. The
detailed recovery procedure and rehearsal requirements are in
[backup and disaster recovery](backup-restore-disaster-recovery.md).

## AIOS-157 validation

```bash
bash bin/check-repository-hygiene
./vendor/bin/sail artisan app:check
./vendor/bin/sail artisan route:list
./vendor/bin/sail composer lint:check
./vendor/bin/sail composer types:check
./vendor/bin/sail artisan test tests/Architecture
```

## AIOS-157 evidence

For a release candidate, the operations owner records the documentation commit,
reviewer and date, architecture-test result, operations-command verification,
links from the repository documentation index, residual gaps, and any
source-of-truth conflict escalated for a human decision.
