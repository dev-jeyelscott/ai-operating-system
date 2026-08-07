# Integration Credential Storage

## Status

Implemented by AIOS-027.

## Ownership

The Integrations module owns provider credential storage.

Project configuration may reference non-secret integration metadata, but it must
never contain credentials, ciphertext, authorization headers, private keys, or
other authentication material.

## Persistence

Credentials are stored in `provider_credentials`.

Each row is scoped by:

- `organization_id`
- `project_id`
- `provider`

The database enforces that the project belongs to the supplied organization.

Only one active credential exists for each project/provider pair. Replacing the
credential is a rotation that increments `version`.

## Encryption

Plaintext credentials are encrypted using Laravel's application encrypter before
database persistence.

The model stores only `secret_ciphertext` and never automatically decrypts the
column.

Plaintext access is available only through
`IntegrationCredentialCipher` and must be limited to authorized provider
operations, such as the AIOS-028 Notion connection test.

## Authorization

Only organization owners and administrators may manage project integration
credentials.

Members and viewers cannot create or rotate credentials.

## Serialization and redaction

`secret_ciphertext` is hidden from model serialization.

Controllers must never return, flash, log, notify, audit, or persist the
plaintext credential outside the encrypted credential table.

Audit events contain only:

- provider
- credential version
- project, organization, actor, and correlation identifiers

## Idempotency and concurrency

The owning project row is locked before credential lookup or mutation.

Submitting the same plaintext credential:

- does not rewrite ciphertext
- does not increment the version
- does not change rotation timestamps
- does not append another audit event

Submitting a different credential:

- replaces the ciphertext
- increments the version
- records the rotation actor and timestamp
- appends an `integration.credential.rotated` audit event

## Application-key rotation

Production encryption-key rotation uses:

- `APP_KEY` for new encryption
- `APP_PREVIOUS_KEYS` for graceful decryption of existing ciphertext

Previous keys must remain configured until every value encrypted with them has
been rotated or deliberately re-encrypted and verified.

Never commit application encryption keys to source control.

## Recovery

A corrupted or undecryptable credential must not be silently replaced by an
empty value.

The operation should fail, preserve the row, emit sanitized operational
evidence, and require an authorized owner or administrator to submit a new
credential.