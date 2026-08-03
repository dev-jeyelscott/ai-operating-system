# Backup and restore

## Purpose

Recover PostgreSQL data or an object version under controlled authorization.

## Trigger conditions and severity guidance

Use for confirmed data loss, corruption, failed migration recovery, or object-version restoration. Treat production recovery as critical.

## Required authorization and safety constraints

Follow [backup and disaster recovery](../operations/backup-restore-disaster-recovery.md). Do not restore over the active production database as the first step.

## Preconditions and diagnosis

Identify the incident time, release, backup checksum, target database, bucket/key/version, and required human recovery approval.

## Containment and recovery procedure

```bash
bash bin/backup-database
RESTORE_CONFIRM_DATABASE=aios_restore_candidate bash bin/restore-database \
  --backup /secure/path/to/backup --target aios_restore_candidate
bash bin/restore-object-version --bucket BUCKET_NAME --key OBJECT_KEY --version-id VERSION_ID
bash bin/dr-rehearsal
```

## Verification

Validate archive contents, checksum, isolated restore probes, application health, restored object checksum, and worker reload after approved cutover.

## Rollback or abort conditions

Abort a cutover on failed checksum, failed integrity probe, incompatible release, or missing approval. Keep the prior database and object versions recoverable.

## Evidence to retain, escalation, and follow-up actions

Retain manifests, checksums, start/end times, operator, recovery decision, and data probes. Escalate production restoration and document corrective actions.
