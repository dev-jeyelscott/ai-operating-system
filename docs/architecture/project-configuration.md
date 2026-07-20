# Project Configuration Architecture

## Ownership

The Projects module owns the current project configuration aggregate.

Integration credentials and provider secrets are excluded. They belong to
encrypted integration storage implemented by AIOS-027.

## Persistence

Each project has exactly one current `project_configurations` row.

- `schema_version` identifies the serialized contract shape.
- `revision` identifies material changes to the project's values.
- Queryable and constraint-heavy values use typed columns.
- Extensible lists and policy maps use PostgreSQL `jsonb`.
- `project_id` is unique.
- Configuration is deleted when its owning project is deleted.

## Schema version 1

The serialized contract contains:

```text
schema_version
revision
technology_stack
repository
validation_commands
required_documents
policy
notifications
```

## Technology stack

- Languages
- Frameworks
- Databases
- Infrastructure
- Package managers
- Runtimes

## Repository

- Provider
- Repository URL
- Default branch
- Integration branch

Schema version 1 supports GitHub repository metadata.

Repository URLs:

- Must use HTTPS.
- Must use the `github.com` host.
- Must contain exactly one owner and repository segment.
- May contain the terminal `.git` clone suffix.
- Must not contain credentials, ports, query strings, or fragments.
- Must not point to repository subpages such as issues or pull requests.

Branch values follow Git reference-name syntax. Repository metadata validation
does not check whether a branch or repository exists.

Repository configuration is metadata only. Saving it performs no DNS lookup,
GitHub API request, clone, fetch, checkout, push, or repository write.

AIOS-024 separately enforces the automated integration-branch policy,
including rejection of `main` as an automated target.

## Validation commands

- Build
- Test
- Lint
- Static analysis
- Security

Commands are persisted by this schema but are not executed by AIOS-021.

## Project policy

- Default reasoning
- Provider allow list
- Provider fallback order
- Budget limit in minor units
- Budget currency
- Automatic retry limit
- Autonomy level
- Approval policy

## Notifications

- Channel identifiers
- Event identifiers

## Versioning rules

1. Increment schema_version only when the serialized structure or field semantics change.
2. Add readers or upcasters before writing a newer schema version.
3. Never silently interpret an unsupported schema version.
4. Increment revision only for material configuration changes.
5. AIOS-031 will persist immutable history keyed by project and revision.
6. Context snapshots must use toVersionedArray() instead of raw model serialization.

## Security rules

- Never store access tokens, passwords, private keys, cookies, or provider
- credentials in project configuration.
- Never log complete project configuration request bodies.
- Future HTTP requests must validate both scalar fields and JSON structures.
- Preserve conservative approval defaults.
- Access configuration through an organization-scoped project.
- Do not create direct, unscoped configuration routes.
- Repository metadata does not authorize repository writes.

## Deferred tickets

- AIOS-022: setup wizard and persisted wizard progress
- AIOS-024: reject main as an automated integration target
- AIOS-025: validation-command rules
- AIOS-026: policy mutation and validation
- AIOS-027: encrypted integration credentials
- AIOS-028: Notion connection verification
- AIOS-029: project completeness evaluator
- AIOS-030: settings and integration screens
- AIOS-031: immutable configuration history and audit events

## Validation command configuration

Every project configuration stores the following required command metadata:

- `build_command`
- `test_command`
- `lint_command`
- `static_analysis_command`
- `security_command`

Command values are trimmed, bounded to 1,000 characters, and restricted to a
single line without unsafe control characters.

Validation commands are configuration metadata. Saving or validating a command
does not execute it, resolve executables, access the configured repository, or
produce verified test or CI evidence.

Normal shell composition syntax remains permitted because supported project
stacks are provider-independent. Execution safety belongs to the future
sandboxed execution-provider and runtime-policy layers.

Credentials must not be embedded in command strings. Commands should reference
environment variables or encrypted integration credentials. The command values
must not be written to logs unless the logging path applies appropriate
redaction and access controls.

Material command changes increment the project configuration revision.
Submitting an identical normalized command set is a no-op and does not create a
new revision.

## Project completeness evaluation

`EvaluateProjectCompleteness` is the authoritative read-only evaluator for
project configuration readiness.

It inspects persisted configuration directly rather than treating wizard
navigation state as configuration truth.

The evaluator verifies:

- supported configuration schema version;
- technology stack;
- repository metadata and integration-branch policy;
- encrypted Notion credential presence;
- latest Notion connection state;
- credential-test freshness;
- verified Notion workspace and database identifiers;
- build, test, lint, static-analysis, and security commands;
- required document policy;
- provider allowlist and fallback order;
- execution, approval, budget, retry, autonomy, and notification policy;
- final project setup confirmation.

Every blocker contains:

- a stable machine-readable key;
- the setup step that owns remediation;
- a human-readable explanation;
- a specific remediation instruction.

The evaluator:

- performs no external requests;
- decrypts no credential;
- exposes no ciphertext;
- performs no writes;
- emits no audit event;
- creates no configuration revision;
- remains organization-scoped.

AIOS-030 may expose the result through project settings. The future StartProject
preflight must reuse this evaluator instead of duplicating configuration checks.