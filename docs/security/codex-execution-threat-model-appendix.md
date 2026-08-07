# Codex Execution Threat-Model Appendix

- Version: 1.0
- Status: Approved architecture baseline
- Date: 2026-08-04
- Ticket: AIOS-241
- Parent threat model: `docs/security/threat-model.md`
- Governing ADR: `docs/adr/0008-use-codex-app-server-with-laravel-managed-isolated-execution.md`
- Review owner: Security Reviewer
- Approval owner: Product Owner

## 1. Purpose

This appendix defines the additional threats introduced by integrating Codex as
a real execution provider.

It supplements the MVP threat model. It does not enable Codex execution,
repository writes, merges, or deployment.

## 2. Scope

This appendix covers:

- Codex App Server process execution
- stdio protocol transport
- Provider sessions, threads, turns, items, and events
- Provider approvals
- Provider credentials
- Attempt-specific provider configuration
- Read-only planning and QA
- Workspace-write development
- Command execution
- Network permission requests
- Workspace lifecycle
- Cancellation and recovery
- Provider transcripts
- Provider result validation
- Future repository adapters
- Simulation fallback
- Independent QA

## 3. Protected assets

- User and organization identity
- Project authorization context
- Approved project documents
- Immutable project context snapshots
- Provider credentials
- GitHub App credentials
- Repository source and history
- Protected branches
- Execution and attempt lineage
- Ticket execution leases
- Approval requests and decisions
- Provider sessions and event streams
- Workspaces
- Validation output
- Artifacts and evidence
- Audit history
- Cost and usage budgets
- Application infrastructure credentials
- Production network and cloud metadata

## 4. Trust boundaries

1. Laravel application to Horizon worker
2. Horizon worker to Codex gateway
3. Codex gateway to child process over stdio
4. Child process to attempt-specific provider home
5. Child process to isolated workspace
6. Child process to command execution boundary
7. Child process to network enforcement boundary
8. Provider approval request to Laravel approval engine
9. Provider event stream to normalized event persistence
10. Provider output to application result validator
11. Laravel repository adapter to GitHub
12. Layer 2 execution to Layer 3 review
13. Simulation provider to real-provider fallback
14. Recovery scanner to an orphaned process or workspace

## 5. Governing invariants

- Laravel owns authorization and workflow state.
- Provider messages are untrusted.
- Codex cannot grant itself permissions.
- Codex cannot persist application approval.
- Codex cannot receive GitHub write credentials.
- Codex cannot transition a ticket.
- Codex cannot approve its own work.
- Codex cannot merge or deploy.
- Every process is bound to one attempt.
- Every writable workspace is disposable and non-shared.
- Network is denied by default.
- Provider output begins as Reported evidence.
- Simulation remains visibly unverified.
- External side effects are Laravel-controlled and idempotent.
- `develop` is the only normal automated pull-request target.
- `main` remains forbidden.

## 6. Threat register

| ID | Threat | Severity | Required controls | Primary follow-up |
|---|---|---:|---|---|
| CDX-001 | Cross-tenant provider context or session reuse | Critical | One process, provider home, workspace, session, and attempt lineage per execution attempt | AIOS-244, AIOS-245 |
| CDX-002 | Prompt injection attempts to change policy or authorization | Critical | Treat all input as data, versioned instruction assembler, immutable system policy, output validation | AIOS-249, AIOS-251 |
| CDX-003 | Malformed protocol message advances execution | Critical | Version-pinned schema, message bounds, strict parsing, normalization, result validation | AIOS-244, AIOS-248 |
| CDX-004 | Provider process escapes its runtime isolation | Critical | Non-root process, no Docker socket, bounded OS/container sandbox, no host secrets | AIOS-256, AIOS-258 |
| CDX-005 | Writable workspace affects another attempt or host checkout | Critical | Unique disposable workspace, verified root, no shared write mount, cleanup and quarantine | AIOS-256, AIOS-257 |
| CDX-006 | Provider credential leaks into browser, logs, artifacts, or prompts | Critical | Existing encrypted credential boundary, attempt-specific materialization, redaction, cleanup | AIOS-243, AIOS-285 |
| CDX-007 | Provider receives unrestricted network access | Critical | Network denied by provider and OS boundary, exact allowlist approval, no private or metadata access | AIOS-246, AIOS-258 |
| CDX-008 | Command execution exceeds approved capability | Critical | Laravel command policy, executable and argument validation, resource bounds, approval bridge | AIOS-246, AIOS-258 |
| CDX-009 | Path traversal or symlink escape writes outside workspace | Critical | Canonical paths, no-follow cleanup, mount restrictions, symlink and hard-link checks | AIOS-256, AIOS-258 |
| CDX-010 | Forged or replayed provider approval | Critical | Attempt/thread/turn/item binding, canonical fingerprint, expiry, idempotent decision, authorization | AIOS-246 |
| CDX-011 | Duplicate, reordered, or forged provider event corrupts state | High | Internal monotonic sequence, canonical fingerprint, schema validation, terminal ordering guard | AIOS-245, AIOS-248 |
| CDX-012 | Oversized transcript or event causes storage or availability failure | High | Message, event, count, transcript, and output bounds with explicit truncation records | AIOS-244, AIOS-245 |
| CDX-013 | Orphan process continues after job or worker loss | Critical | Heartbeat, liveness reconciliation, process termination, workspace quarantine, retry blocking | AIOS-247 |
| CDX-014 | Cancellation races with successful completion or side effects | Critical | Persist cancellation first, cancellation precedence, interrupt/terminate sequence, transition guards | AIOS-247 |
| CDX-015 | Retry duplicates branch, commit, push, or pull request | Critical | Laravel-controlled adapter, external idempotency keys, immutable SHAs, reconciliation before retry | AIOS-262, AIOS-263 |
| CDX-016 | Provider self-approves or changes workflow state | Critical | No domain access, existing transition services, approval engine, output treated as proposal | AIOS-242, AIOS-246 |
| CDX-017 | Layer 3 inherits Layer 2 bias or writable state | Critical | Separate process, execution, thread, read-only workspace, immutable evidence request | AIOS-264, AIOS-265 |
| CDX-018 | Simulation fallback is represented as real execution | High | New attempt provenance, simulation labels, actual state Unverified, no verified evidence | AIOS-242, AIOS-252 |
| CDX-019 | Codex upgrade silently changes protocol or behavior | High | Version pinning, generated stable schema, contract suite, dependency review, staged rollout | AIOS-244, AIOS-248, AIOS-287 |
| CDX-020 | Cost, queue, or provider overload causes denial of service | High | Tenant concurrency, budgets, bounded queues, backoff, circuit breakers, fallback, metrics | AIOS-284, AIOS-286 |
| CDX-021 | Provider receives application or infrastructure credentials | Critical | Explicit environment allowlist, isolated runtime, no `.env`, DB, Redis, S3, Reverb, or app key | AIOS-243, AIOS-258 |
| CDX-022 | Provider manipulates validation evidence | Critical | Laravel-controlled validation runner, immutable command manifest, independent capture | AIOS-260, AIOS-261 |
| CDX-023 | Provider changes immutable repository base | Critical | Verified repository preflight, immutable SHA checkout, cleanliness and drift checks | AIOS-255, AIOS-257 |
| CDX-024 | Provider creates a pull request targeting `main` | Critical | Deterministic branch policy, Laravel-side target validation, GitHub protection, negative tests | AIOS-262, AIOS-263 |
| CDX-025 | Live provider dependency makes normal CI non-deterministic | High | Fake App Server in PR CI, no credentials, separate approved live smoke workflow | AIOS-248, AIOS-287 |

## 7. Abuse cases

### 7.1 Prompt requests credentials

A ticket, repository file, AGENTS file, comment, test output, or provider message
asks Codex to reveal credentials or environment variables.

Expected result:

- Content remains untrusted.
- Provider context excludes secrets.
- Runtime environment contains only approved provider authentication.
- Output is redacted.
- Attempt cannot access Laravel, database, Redis, GitHub write, or cloud
  credentials.

### 7.2 Provider asks for broad network access

Codex requests unrestricted internet access or a wildcard domain.

Expected result:

- Request is denied.
- No provider-side policy amendment becomes authoritative.
- Human approval cannot bypass system-level forbidden destinations.
- Denial and request context are audited.

### 7.3 Provider requests a command outside the workspace

Codex requests a command with a working directory outside the attempt workspace
or uses path traversal, symlink traversal, shell substitution, or redirected
output to escape.

Expected result:

- Request is rejected before execution.
- No approval is created for a deterministically forbidden action.
- Security evidence records the rejection.
- Repeated violations may fail the attempt.

### 7.4 Provider reports tests passed without independent execution

Expected result:

- Claim is stored as Reported evidence.
- Laravel-controlled validation runs separately.
- Workflow cannot advance using the claim alone.

### 7.5 Worker dies while Codex remains alive

Expected result:

- Heartbeat becomes stale.
- Recovery prevents a second process from starting.
- Orphan process is terminated.
- Workspace is reconciled and quarantined if cleanup cannot be proven.
- A retry is created only through existing resilience policy.

### 7.6 Approval reply is replayed

Expected result:

- Attempt, request fingerprint, thread, turn, item, status, and expiry are
  checked.
- Replayed or cross-attempt decision is rejected.
- Exactly one terminal approval decision exists.

### 7.7 Provider tries to target `main`

Expected result:

- Target fails deterministic repository policy.
- No GitHub write occurs.
- Attempt records a blocking policy violation.
- Human approval cannot override the system prohibition.

### 7.8 Layer 2 session is supplied to QA

Expected result:

- QA eligibility fails.
- Layer 3 requires a separate execution, attempt, process, thread, and read-only
  context.
- No merge advisory is generated from a self-review-only execution.

## 8. Security requirements by rollout stage

### Stage 1 — Foundation

Required:

- Protocol schema validation
- Credential isolation
- Fake-server contract tests
- Approval replay protection
- Heartbeat and cancellation
- Transcript bounds
- Simulation fallback

Repository writes remain disabled.

### Stage 2 — Read-only planning

Required:

- Versioned context assembler
- Prompt-injection separation
- Read-only workspace
- Network denied
- Source-reference validation
- Human roadmap approval

### Stage 3 — Controlled development

Required:

- GitHub App preflight
- Disposable workspace
- Immutable base SHA
- Command allowlist
- Dual-layer network denial
- Independent validation
- Real diff manifest
- Idempotent repository adapter
- Draft pull request targeting `develop`
- Duplicate-side-effect acceptance suite

### Stage 4 — Independent QA

Required:

- Separate process and thread
- Read-only QA workspace
- Immutable base/head evidence
- GitHub and CI observation
- Deterministic blocking checks
- Human merge decision

### Stage 5 — Production hardening

Required:

- Provider circuit breaker
- Concurrency and cost limits
- Operational metrics
- Complete security review
- Separate live smoke workflow
- Recovery drills
- No unresolved Critical or High finding

## 9. Required evidence

Before enabling each stage, retain:

- Approved ADR
- Approved threat-model appendix
- Generated protocol schema fingerprint
- Fake-server contract results
- Credential redaction results
- Approval replay and expiry tests
- Cancellation and orphan cleanup tests
- Sandbox and network tests
- Workspace escape tests
- Provider result rejection tests
- Duplicate-side-effect results
- Human reviewer names
- Security sign-off
- Rollback procedure

## 10. Residual risk policy

Critical and High risks cannot be accepted merely because:

- Codex is a trusted vendor
- The provider reported success
- The sandbox configuration says read-only
- A command appears harmless
- A human approved a broader action
- The same process worked in local development
- The operation is retried
- The provider output looks structurally correct

Critical and High findings must be mitigated or proven false positive before the
affected capability is enabled.

## 11. Review triggers

Review this appendix when:

- Codex transport changes
- Provider version changes
- Stable protocol schema changes
- Experimental API is enabled
- Shared processes or workspaces are proposed
- Network policy broadens
- Command policy broadens
- Repository writes are enabled
- GitHub credentials or scopes change
- Layer 3 independence changes
- Automated merge is proposed
- Deployment is proposed
- A provider security incident occurs
