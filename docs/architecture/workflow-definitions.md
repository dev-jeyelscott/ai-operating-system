# Workflow Definitions and Versioning

## Decision

`workflow_definitions` stores one row per immutable workflow-definition version.

A stable `definition_key` groups versions of the same workflow. The pair
`(definition_key, version)` is unique. Existing rows are never edited or deleted;
a behavioral change is published as a new version.

## Stored contract

Each row stores:

- `definition_key`: stable machine identifier, for example `project_delivery`
- `version`: immutable business-contract version
- `schema_version`: version of the JSON document shape
- `name` and `description`: human-readable metadata
- `definition`: declarative states, terminal states, transitions, and guard IDs
- `checksum_sha256`: deterministic checksum of metadata and canonical definition
- `created_at`: publication timestamp

Guard values are stable policy identifiers. They are not executable PHP,
provider prompts, closures, or arbitrary expressions.

## Workflow-instance binding

AIOS-047 must add a required `workflow_definition_id` foreign key to
`workflow_instances` with `restrictOnDelete()`.

A new workflow instance may resolve the latest published version once during
creation. After creation, all execution and transition logic must use the stored
`workflow_definition_id`; it must never dynamically follow a newer version.

## Versioning rules

1. Publish version 1 for the first contract.
2. Publish a new integer version for any behavioral change.
3. Never update a published version in place.
4. Never add mutable `current_version_id` or `is_active` fields to historical rows.
5. Use `schema_version` only when the JSON document shape changes.
6. Preserve old versions while any workflow instance references them.
7. Treat checksum mismatch for an existing key/version as a conflict.

## Recovery

The migration rollback drops only the new workflow-definition table and its
append-only trigger function. Do not roll back after workflow instances begin
referencing definition rows; use a forward migration instead.
