# Backup and restore

## Purpose

Recover PostgreSQL data or restore an earlier object-storage version under
controlled authorization.

## Trigger conditions and severity guidance

Use this runbook for:

- confirmed data loss;
- confirmed corruption;
- failed migration recovery;
- accidental object replacement;
- accidental object deletion where a prior version remains recoverable.

Treat every production restoration as a critical operation.

## Required authorization and safety constraints

Follow the complete
[backup and disaster-recovery procedure](../operations/backup-restore-disaster-recovery.md).

Do not:

- restore over the active production database as the first step;
- pre-encode the S3 object key or version ID;
- delete current or non-current object versions during recovery;
- retry a failed copy until the existing current version is inspected;
- expose credentials in command history or retained evidence.

The object restore command accepts the raw key and raw version ID and performs
the required S3 CopySource encoding internally.

## Preconditions and diagnosis

Record:

- incident identifier;
- incident time;
- affected release;
- operator;
- authorized recovery approver;
- database backup path and checksum;
- target database;
- S3 bucket;
- exact raw object key;
- exact source version ID;
- whether the object version is archived;
- expected object checksum or trusted validation method.

Confirm the required IAM permissions are available:

- `s3:GetBucketVersioning`;
- `s3:GetObjectVersion`;
- `s3:GetObject`;
- `s3:PutObject`.

An archived object version must first be restored to an accessible storage tier
through the approved S3 archive-recovery process.

## Containment and recovery procedure

### PostgreSQL

```bash
bash bin/backup-database

RESTORE_CONFIRM_DATABASE=aios_restore_candidate \
    bash bin/restore-database \
        --backup /secure/path/to/backup \
        --target aios_restore_candidate
```

Restore into an isolated database, validate it, and obtain cutover approval
before changing the application connection.

## Object version

Pass the object key and version ID exactly as returned by S3:

```bash
bash bin/restore-object-version \
    --bucket BUCKET_NAME \
    --key 'raw/path with reserved+characters/object.json' \
    --version-id 'RAW_VERSION_ID'
```

The command:

1. verifies bucket versioning;
2. downloads the selected historical version;
3. calculates its SHA-256 checksum;
4. URL-encodes the versioned CopySource;
5. copies the historical version into the same key;
6. creates a new current object version;
7. downloads the current object;
8. compares both checksums;
9. records source and new version metadata.

## Complete local rehearsal

```bash
bash bin/dr-rehearsal
```

## Verification

For database recovery, verify:

- archive contents;
- backup checksum;
- isolated database restore;
- integrity probes;
- compatible application release;
- application health;
- queue and worker state.

For object recovery, verify:

- source version ID;
- copy response source version ID when supplied;
- new current version ID when supplied;
- selected-version checksum;
- restored-current-object checksum;
- application behavior;
- preservation of prior version history.

## Rollback or abort conditions

Abort cutover or further mutation when:

- checksum verification fails;
- S3 reports a different source version;
- the selected version is unavailable or archived;
- database integrity probes fail;
- application release compatibility is unknown;
- approval is missing;
- a concurrent recovery is detected.

Keep the previous database and all object versions recoverable.

## Evidence to retain, escalation, and follow-up actions

Retain:

- manifests;
- checksums;
- source and restored version IDs;
- copy response;
- start and completion times;
- operator;
- recovery approver;
- recovery decision;
- data and application probes;
- failures and corrective actions.

Escalate every production restoration and document the root cause and prevention
work.
