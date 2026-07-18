# Abuse-Case Register

## ABUSE-001 — Cross-project identifier substitution

An authenticated user changes a project, document, task, or execution ID to
access another project's resources.

Required controls:

- Project-scoped route binding
- Server-side authorization policy
- Tenant-safe query restrictions
- Cross-project denial tests
- Audit record for suspicious privileged access

## ABUSE-002 — Uploaded document instructs the agent to ignore policy

A document contains text that impersonates system instructions or requests
credential disclosure, branch-policy bypass, or unauthorized commands.

Required controls:

- Treat document text as untrusted content
- Keep authorization and transition rules outside prompts
- Detect and surface suspicious instructions
- Redact secrets before provider dispatch
- Require human approval for consequential actions

## ABUSE-003 — Retry creates duplicate Notion tickets

A partial failure causes a publication workflow to retry and create duplicate
external pages.

Required controls:

- Stable external task keys
- Idempotent upsert
- Transactional outbox
- Reconciliation report
- Retry tests

## ABUSE-004 — Provider output claims tests passed

A simulation or external provider reports successful tests without command
evidence.

Required controls:

- Evidence classification
- Required exit code and command output for verified test evidence
- UI labels for simulated and reported evidence
- Verification gate before completion

## ABUSE-005 — Credential is included in logs or model context

A token appears in an exception, request payload, configuration object, or
provider request.

Required controls:

- Central redaction
- No complete request-body logging
- Encrypted credential storage
- Secret references rather than ordinary domain fields
- Security tests with sentinel secrets

## ABUSE-006 — Future agent executes a destructive repository command

A provider attempts to remove files, rewrite protected history, expose secrets,
or push directly to a protected branch.

Required controls:

- Isolated temporary workspace
- Command and path allowlists
- CPU, memory, disk, time, and network limits
- Protected-branch policy
- Human approval
- Complete command evidence and audit trail

## ABUSE-007 — Forged real-time event changes UI truth

A client receives or constructs a real-time event that suggests a workflow state
not present in authoritative storage.

Required controls:

- Authorized project channels
- Projection state derived from backend truth
- Event schema validation
- Refresh and reconstruction from durable state
- No client-owned workflow transitions
