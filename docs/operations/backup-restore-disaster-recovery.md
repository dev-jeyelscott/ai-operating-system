# Backup, Restore, and Disaster Recovery

## Scope

This runbook covers recovery of the AI Operating System platform:

- PostgreSQL application database
- Amazon S3 production object storage
- MinIO local object storage
- Application release selection

It does not authorize AI agents to perform production recovery.

## Initial recovery objectives

These values are the initial MVP operational baseline and must be reviewed
before production launch:

- PostgreSQL recovery point objective: 24 hours
- Object-storage recovery point objective: previous committed object version
- Recovery time objective: 4 hours
- Scheduled PostgreSQL backup: daily
- Restore rehearsal: quarterly and before release sign-off
- Pre-migration PostgreSQL backup: every production deployment

## PostgreSQL backup requirements

Production database backups must:

1. Use PostgreSQL custom format.
2. Be encrypted at rest.
3. Be stored outside the application host.
4. Include a SHA-256 checksum.
5. Be validated with `pg_restore --list`.
6. Have documented retention.
7. Be tested through restore into an isolated database.
8. Never contain application secrets in metadata.

Recommended retention:

- 7 daily backups
- 4 weekly backups
- 12 monthly backups

Managed PostgreSQL point-in-time recovery should be enabled when supported by
the production provider.

## Object-storage requirements

The production Amazon S3 bucket must have:

- Versioning enabled
- Encryption enabled
- Public access blocked
- Least-privilege application IAM policy
- Non-current version lifecycle policy
- Access logging or CloudTrail data events where required
- Cross-account or cross-region replication when the risk assessment requires it

A previous object version is restored by copying that version into the same
key. The copy becomes a new current version and preserves the previous history.

## Database restore procedure

1. Declare the incident and identify the recovery timestamp.
2. Stop write traffic or pause write-producing workers when consistency
   requires it.
3. Identify the application release matching the backup.
4. Verify backup checksum.
5. Validate the archive table of contents.
6. Restore into a newly named database.
7. Run integrity queries.
8. Configure a staging application instance against the restored database.
9. Run `php artisan app:check`.
10. Run critical API and Playwright smoke tests.
11. Obtain the required human recovery approval.
12. Cut over the application database connection.
13. Restart or reload application workers.
14. Verify `/up`, queue health, Horizon, Reverb, and object storage.
15. Record all recovery evidence and decisions.

Never restore directly over the active production database as the first step.

## Object restore procedure

1. Identify the bucket, object key, and required version ID.
2. Verify bucket versioning is enabled.
3. Download and inspect the selected version.
4. Run `bin/restore-object-version`.
5. Verify the restored object checksum and application behavior.
6. Record the new current version and recovery evidence.

## Local rehearsal

Run:

```bash
PG_TOOLS_MODE=docker-compose \
PG_DOCKER_SERVICE=pgsql \
PGHOST=127.0.0.1 \
PGPORT=5432 \
PGUSER=sail \
PGPASSWORD=password \
AWS_ACCESS_KEY_ID=sail \
AWS_SECRET_ACCESS_KEY=password \
AWS_DEFAULT_REGION=us-east-1 \
AWS_ENDPOINT_URL=http://127.0.0.1:9000 \
    bash bin/dr-rehearsal
```

The rehearsal creates only isolated temporary databases and a uniquely named
temporary object-storage bucket.

## Evidence

Each rehearsal must retain:

- Workflow run URL
- Source commit SHA
- Backup manifest
- Backup checksum
- Restore manifest
- Data verification result
- Object version ID
- Restored object checksum
- Start and completion timestamps
- Failures and corrective actions
- Operator or reviewer
