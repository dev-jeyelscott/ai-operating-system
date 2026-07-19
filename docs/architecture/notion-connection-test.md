# Notion Connection Test

## Purpose

AIOS-028 validates that a project-scoped encrypted Notion credential can identify its workspace and read the configured task database.

## Scope

The connection test performs only:

- `GET /v1/users/me`
- `GET /v1/databases/{database_id}`

It does not:

- query ticket rows;
- create pages;
- update pages;
- update databases;
- delete content;
- publish roadmaps or tickets.

The full reusable Notion API client and publication behavior remain owned by AIOS-081 and later publication tickets.

## Credential handling

- Credentials are stored only in `provider_credentials`.
- Plaintext exists only at the explicit credential/provider boundary.
- Candidate replacement credentials are persisted only after a successful test.
- Credentials, ciphertext, authorization headers, and raw response bodies are never audited or returned to Inertia.

## Authorization

Only users permitted by `ProjectPolicy::manageIntegrations` may store credentials or execute a connection test.

All persistence queries require both `organization_id` and `project_id`.

## Connection state

Safe metadata is stored in `project_integrations`:

- provider;
- workspace ID and name;
- database ID and name;
- current connection status;
- stable failure category;
- last provider request ID;
- last tested user and timestamp;
- last successful connection timestamp.

## Failure handling

Provider responses are mapped to application-owned categories:

- invalid token;
- missing read capability;
- database not shared;
- workspace mismatch;
- rate limited;
- provider unavailable;
- malformed provider response;
- generic connection failure.

Raw provider error messages are not persisted.

## Reliability

The HTTP request uses:

- bounded connection and total timeouts;
- bounded retries for connection errors, conflicts, rate limiting, and server failures;
- the Notion `Retry-After` value when it is short enough for an interactive request;
- no database transaction around network I/O;
- credential-version validation before persisting a result.

## Recovery

A failed test does not advance setup.

A successful retry overwrites the failed connection state and advances the project setup wizard.

Repeating an identical successful test does not increment the project configuration revision.