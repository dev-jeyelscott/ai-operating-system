# AI Operating System — Final MVP Specification

**Version:** 1.0  
**Status:** Final approved MVP scope  
**Updated:** 2026-07-18  
**Depends on:** AI Operating System Full Product and Technical Specification v1.2

---

## 1. MVP Objective

Build a usable simulation-first product that demonstrates the final AI Operating System experience from project creation through merge decision.

The MVP must prove:

- The user journey is valuable and understandable.
- The three-layer agent model works operationally.
- Workflow states, approvals, retries, and evidence are reliable.
- Notion can serve as the task-level source of truth.
- The interactive 3D office accurately represents workflow activity.
- Simulated engineering operations can later be replaced with real providers without changing the domain workflow.

---

## 2. MVP Success Scenario

A successful demo must allow a user to:

```text
1. Log in.
2. Create a project.
3. Configure project details, Notion, repository metadata, policies, and commands.
4. Upload and approve documentation.
5. Click "Start this Project".
6. Watch Layer 1 analyze the project and generate a roadmap.
7. Review phases, tasks, dependencies, risks, and readiness.
8. Approve development start.
9. Publish tickets to a real Notion database.
10. Watch Layer 2 claim the next workable ticket and simulate implementation.
11. See simulated branch, commit, validation, and PR artifacts targeting develop.
12. Watch Layer 3 perform an independent simulated QA review.
13. Receive a merge-readiness recommendation and risk report.
14. Approve, request changes, escalate, or defer the simulated merge.
15. Inspect the complete workflow in the 3D office, dashboard, timeline, and audit log.
```

---

## 3. MVP Product Boundaries

### 3.1 Real capabilities

- User authentication
- Organization and project isolation
- Project CRUD and configuration
- Notion OAuth or integration-token connection
- Repository metadata configuration
- Document upload and storage
- Document versioning, parsing, review, and approval
- Project context snapshot
- Start Project preflight and idempotent command
- Workflow state machine
- Approval engine
- Policy and reasoning resolution
- Layer 1 structured simulation
- Roadmap and task persistence
- Real Notion ticket publication and reconciliation
- Ticket eligibility and lease management
- Layer 2 structured simulation
- Layer 3 structured simulation
- Merge decision center
- In-app notifications
- Evidence and artifact records
- Audit history
- Usage and cost estimates
- 3D office and accessible dashboard
- Failure, retry, blocker, timeout, and recovery scenarios

### 3.2 Simulated capabilities

- Repository checkout and code modification
- Build, test, lint, and security command execution
- Git branches, commits, and pushes
- Real GitHub pull requests
- CI checks
- Real source-code QA
- Merge
- Deployment

### 3.3 Explicitly excluded

- Direct repository writes
- Real merge or deployment
- Pull requests to `main`
- Autonomous high-risk decisions
- Production incident response
- Billing and payments
- Agent marketplace
- Multi-provider optimization
- Native mobile application
- External notification channels

---

## 4. Recommended MVP Architecture

### 4.1 Architecture style

A modular monolith is required for MVP simplicity, transactional consistency, and maintainability.

### 4.2 Technology stack

| Area | Decision |
|---|---|
| Backend | Laravel 13 |
| Frontend | React + TypeScript through Inertia |
| 3D | React Three Fiber + Drei |
| Styling | Tailwind CSS |
| Database | PostgreSQL |
| Queue and locks | Redis + Horizon |
| Real-time | Laravel Reverb or SSE behind an abstraction |
| Files | S3-compatible object storage |
| Backend tests | PHPUnit or Pest |
| Frontend tests | Vitest + React Testing Library |
| End-to-end tests | Playwright |
| Local environment | Docker Compose |

### 4.3 Module boundaries

- Identity
- Organizations
- Projects
- Documents
- Integrations
- Planning
- Workflow
- Tickets
- Executions
- QA and Merge Advisory
- Evidence
- Notifications
- Audit
- Office Projection

---

## 5. Required Screens and Routes

### 5.1 Authentication

- `/login`
- `/register` if self-service registration is enabled

### 5.2 Projects

- `/projects`
- `/projects/create`
- `/projects/{project}`
- `/projects/{project}/settings`
- `/projects/{project}/integrations`

### 5.3 Documents

- `/projects/{project}/documents`
- `/projects/{project}/documents/{document}`
- Upload, version, approve, reject, and supersede actions

### 5.4 Planning

- `/projects/{project}/roadmap`
- `/projects/{project}/roadmap/approval`
- Phase, task, dependency, risk, and readiness views

### 5.5 Operations

- `/projects/{project}/office`
- `/projects/{project}/dashboard`
- `/projects/{project}/tickets`
- `/projects/{project}/executions/{execution}`
- `/projects/{project}/approvals`
- `/projects/{project}/notifications`
- `/projects/{project}/audit`

The office and dashboard must expose equivalent actions.

---

## 6. Project Setup Requirements

The project wizard must collect:

1. Project identity
2. Technology stack
3. Repository metadata
4. Notion integration and database
5. Validation commands
6. Required document policy
7. Provider and reasoning defaults
8. Budget and retry limits
9. Approval and autonomy settings
10. Notification settings

The integration branch defaults to `develop` and cannot be set to `main` for automated work.

### Acceptance criteria

- Required fields are validated server-side.
- Credentials are stored encrypted and never displayed after save.
- A Notion connection test can be run.
- Repository URL format is validated without performing writes.
- Incomplete configuration prevents project start and lists exact remediation steps.

---

## 7. Document Center

### Capabilities

- Multi-file upload
- File-type and size validation
- Checksum and version tracking
- Parsing status
- Document classification
- Review notes
- Approve, reject, or supersede
- Required-document checklist
- Context snapshot preview

### MVP parsing

The MVP may use deterministic fixtures or a simulation provider for semantic analysis, but file ingestion, versioning, approval, and checksums are real.

### Acceptance criteria

- An approved version cannot be silently replaced.
- Starting a project snapshots exact approved versions.
- Rejected and superseded documents are excluded.
- Malicious document instructions are displayed as document content and cannot override system policy.
- Upload failures are recoverable and auditable.

---

## 8. Start This Project

### UI behavior

The button must show a preflight summary before execution:

- Configuration completeness
- Notion connection
- Repository metadata
- Required document status
- Estimated simulation cost
- Required human gates

### Command behavior

- Require an idempotency key.
- Create one workflow execution.
- Create one context snapshot.
- Emit progress events.
- Navigate to the office or execution view.
- Return the existing execution on duplicate requests.

### Acceptance criteria

- Double-clicking does not create duplicate workflows.
- An invalid project cannot start.
- Every failed precondition returns a specific correction.
- Start is recorded in the audit log.

---

## 9. Layer 1 MVP — Planning Simulation

### Inputs

- Project configuration
- Approved context snapshot
- Existing roadmap and tickets
- Simulation scenario and seed

### Outputs

- Document summary
- Gap and conflict report
- Roadmap
- Phases
- Milestones
- Tasks
- Dependencies
- Risks
- Acceptance criteria
- Required evidence
- Suggested logical agent
- Required reasoning
- Readiness decision

### Human gate

The user can:

- Approve and publish
- Request regeneration with feedback
- Edit allowed roadmap fields
- Reject and return to documents

### Acceptance criteria

- Same input and deterministic seed produce the same output.
- Every task references source documents.
- Every task has acceptance criteria, evidence, risk, agent, and reasoning.
- Dependencies form an acyclic graph or produce a blocking error.
- No ticket is published before approval.

---

## 10. Real Notion Ticket Publication

### Capabilities

- Map internal tasks to Notion pages.
- Create or update pages using stable external keys.
- Populate required properties and ticket body.
- Store Notion page ID and URL.
- Retry transient failures.
- Reconcile partial publication.

### Acceptance criteria

- Retrying does not create duplicate pages.
- A publication summary shows created, updated, failed, and skipped tickets.
- Failed tickets can be retried individually or as a batch.
- External edits are detected during reconciliation.
- Conflicting edits require human review.

---

## 11. Ticket Selector and Lease Manager

### Eligibility

- Status is `Ready` or approved `Changes Requested`.
- Dependencies are complete.
- No active blocker exists.
- Required approvals exist.
- No active lease exists.
- Project is active.
- Policy and budget permit execution.

### Lease behavior

- Acquire atomically.
- Record owner and expiry.
- Heartbeat while executing.
- Release on completion, failure, cancellation, or approved recovery.

### Acceptance criteria

- Concurrent selector requests cannot claim the same ticket.
- Blocked tickets are never selected.
- Ranking follows roadmap order, critical path, priority, and age.
- No-workable-ticket is a valid visible state, not an error.

---

## 12. Layer 2 MVP — Development Simulation

### Simulated sequence

```text
Ticket claimed
 -> Implementation plan created
 -> Simulated workspace created
 -> Simulated branch created
 -> Simulated files changed
 -> Simulated tests executed
 -> Simulated commit created
 -> Simulated push completed
 -> Simulated PR created targeting develop
 -> Ticket moved to For QA
```

### Required artifacts

- Implementation plan
- Changed-file manifest
- Simulated diff summary
- Validation report
- Branch name
- Commit SHA clearly marked synthetic
- Pull-request artifact clearly marked synthetic
- Assumptions
- Confidence
- Risks
- Evidence still required for real execution

### Acceptance criteria

- The simulated PR target is always `develop`.
- A scenario attempting `main` is rejected by policy.
- Failed validation prevents transition to `For QA`.
- Retry does not create duplicate branch or PR artifacts.
- The implementation role cannot approve the QA result.

---

## 13. Layer 3 MVP — QA and Merge Advisory Simulation

### Required checks

- Ticket scope
- Acceptance criteria
- Functional correctness
- Architecture
- Security and authorization
- Database changes
- Performance and optimization
- Maintainability
- Tests and CI
- Regression risk
- Rollback complexity
- Target branch

### Required decisions

- Merge Ready
- Merge Ready with Risks
- Changes Requested
- Blocked
- Human Review Required

### User actions

- Approve simulated merge
- Request changes
- Escalate
- Defer

### Acceptance criteria

- QA output uses a stable schema.
- Layer 3 is represented by a separate execution and logical role.
- Blocking findings prevent approval.
- Every risk has severity, description, impact, mitigation, and evidence reference.
- User decisions are auditable.

---

## 14. 3D Office MVP

### Required layout

- Lobby or project selector
- Planning Room
- Development Floor
- QA Laboratory
- Approval Room
- Operations Area

### Required interactions

- Orbit or guided camera navigation
- Click agent to inspect execution
- Click room to filter active work
- Click approval indicator to open decision center
- Click blocker indicator to open remediation details
- Toggle between 3D and dashboard view

### Required visual states

- Idle
- Active
- Waiting
- Blocked
- Retrying
- Completed
- Failed

Detailed action labels must distinguish planning, implementation, validation, PR creation, QA, and human decision states.

### Performance and accessibility

- Lazy-load models and textures.
- Use low-poly assets.
- Support reduced motion.
- Provide quality presets.
- Provide a WebGL failure fallback.
- All actions must be available without 3D.

### Acceptance criteria

- The office reflects backend events, not local timers.
- Refreshing the page reconstructs the same state from read models.
- Agent inspector shows provider and simulation status.
- Simulated activity is visually labeled.
- The dashboard provides feature-equivalent controls.

---

## 15. Notifications and Approval Center

### In-app notifications

- Roadmap ready
- Roadmap blocked
- Ticket claimed
- Execution blocked
- Retry scheduled
- PR artifact created
- QA result ready
- Merge decision required
- Changes requested
- Workflow completed

### Acceptance criteria

- Notifications are deduplicated by event and recipient.
- Read state is persisted.
- Actionable notifications deep-link to the correct decision.
- Approval commands are authorized and idempotent.

---

## 16. Audit, Evidence, and Timeline

The execution view must show:

- State transitions
- Actor and provider
- Input snapshot
- Commands and events
- Artifacts
- Evidence classification
- Approvals
- Retries
- Errors
- Cost estimates

### Acceptance criteria

- Every privileged command creates an audit event.
- Audit events are append-only through the application.
- Simulation never produces verified evidence.
- Timeline ordering is stable using event sequence and timestamp.

---

## 17. Simulation Scenarios

The MVP must ship with at least these selectable scenarios:

1. Happy path
2. Missing required document
3. Conflicting documents
4. Notion publication transient failure
5. No workable ticket
6. Dependency blocked
7. Development validation failure
8. Provider timeout and retry
9. Wrong PR target rejected
10. QA changes requested
11. Merge ready with low risk
12. Merge ready with high risk requiring escalation
13. Duplicate Start Project request
14. Duplicate ticket publication retry

Each scenario must be deterministic for a given seed.

---

## 18. API and Command Surface

Representative commands:

```text
CreateProject
UpdateProjectConfiguration
TestNotionConnection
UploadProjectDocument
ApproveDocumentVersion
StartProject
GenerateRoadmap
ApproveRoadmap
PublishTicketsToNotion
SelectNextWorkableTicket
StartTicketExecution
RetryExecution
CancelExecution
RunQaAssessment
SubmitMergeDecision
PauseProject
ResumeProject
```

Representative query endpoints:

```text
GET /projects
GET /projects/{id}
GET /projects/{id}/preflight
GET /projects/{id}/documents
GET /projects/{id}/roadmap
GET /projects/{id}/tickets
GET /projects/{id}/office-projection
GET /executions/{id}
GET /executions/{id}/timeline
GET /approvals
GET /notifications
```

Exact transport routes may change, but application commands and contracts must remain explicit and testable.

---

## 19. Testing Strategy

### Unit tests

- State transition guards
- Reasoning resolution
- Ticket eligibility and ranking
- Lease behavior
- Evidence classification
- Merge-decision rules
- Simulation determinism

### Integration tests

- Project start transaction
- Context snapshot creation
- Notion publishing idempotency
- Outbox delivery
- Retry and reconciliation
- Authorization and project isolation

### Frontend tests

- Project wizard validation
- Document approval UI
- Roadmap approval
- Agent inspector
- Merge decision center
- Simulation labels

### End-to-end tests

1. Happy-path project lifecycle
2. Document blocker recovery
3. Notion retry without duplicates
4. Development failure and retry
5. QA changes requested loop
6. Merge-ready decision
7. Unauthorized cross-project access rejection
8. 3D unavailable dashboard fallback

---

## 20. Security Acceptance Criteria

- Authorization policies cover every project-scoped action.
- Cross-organization resource access is rejected.
- Connector credentials are encrypted.
- Secrets are redacted from logs and provider context.
- Uploaded files are validated by type and size.
- Document content cannot override system policy.
- Provider results are schema-validated.
- Automated PR target policy rejects `main`.
- Privileged actions are audited.
- Rate limits protect start, retry, upload, and approval actions.

---

## 21. Operational Requirements

- Queue jobs are observable through Horizon.
- Failed jobs support bounded retry and manual replay.
- Health endpoint checks application, database, Redis, storage, and integration status.
- Structured logs contain correlation and execution IDs.
- Metrics track workflow success, failures, retries, duration, queue time, and cost estimate.
- Database backups and restoration procedures are documented.
- Local setup is available through Docker Compose.

---

## 22. MVP Delivery Order

### Phase 0 — Foundation

- Repository and modular boundaries
- Docker environment
- PostgreSQL and Redis
- CI quality gates
- Authentication foundation

### Phase 1 — Projects and configuration

- Organizations
- Project wizard
- Policies and commands
- Integration metadata

### Phase 2 — Documents

- Uploads
- Versions
- Parsing states
- Approval and context snapshots

### Phase 3 — Workflow foundation

- Commands
- State machine
- Outbox
- Audit
- Notifications
- Retry and recovery

### Phase 4 — Layer 1 and roadmap

- Planning simulation
- Roadmap UI
- Human approval
- Notion publishing

### Phase 5 — Ticket execution

- Selector
- Leases
- Layer 2 simulation
- Execution inspector

### Phase 6 — QA and merge advisory

- Layer 3 simulation
- Risk report
- Decision center
- Changes-requested loop

### Phase 7 — 3D office

- Office projection
- Real-time events
- Rooms and agents
- Inspector integration
- Dashboard fallback

### Phase 8 — Hardening and UAT

- Scenario coverage
- Security review
- Performance review
- Accessibility
- Recovery tests
- Documentation and demo data

---

## 23. Definition of Done

The MVP is Done only when:

- All mandatory acceptance criteria pass.
- Required automated tests pass in CI.
- No unresolved critical or high security findings remain.
- The full happy-path demo works from a clean environment.
- Required failure and retry scenarios work without duplicate side effects.
- Notion publication is real and idempotent.
- Simulation labels are unambiguous.
- The 3D office and dashboard remain consistent after refresh and reconnection.
- The user can make an evidence-backed simulated merge decision.
- The architecture review confirms real providers can replace simulation through existing interfaces.
- Setup, architecture, workflow, security, operations, and demo documentation are complete.

---

## 24. Post-MVP Entry Criteria

Real provider work may begin only after:

- MVP acceptance is complete.
- Workflow transition metrics are stable.
- Duplicate-side-effect tests pass.
- Security review approves repository access design.
- A sandboxed execution environment is selected.
- GitHub permissions and branch protection are validated.
- Evidence contracts for real commands and CI are approved.
- Rollback and incident procedures exist.

The first real provider tasks should be low-risk, well-specified, reversible, and easily verified.
