# AI Operating System — Final Baseline Architecture

**Version:** 1.2  
**Status:** Final approved product and MVP baseline  
**Updated:** 2026-07-18  
**Owner:** Product Owner / System Architect

---

## 1. Product Definition

The AI Operating System is a simulation-first, provider-independent software-development orchestration platform presented as an interactive 3D digital office.

A user creates and configures a project, uploads the approved project documentation, and selects **Start this Project**. The platform then coordinates three controlled delivery layers:

1. **Planning and Project Intelligence** — understands the project, generates the implementation roadmap, decomposes work into phases and tickets, publishes approved tickets to Notion, and determines project readiness.
2. **Development Execution** — selects the next workable Notion ticket, implements or simulates the change in an isolated branch, validates it, commits and pushes the work, and creates a pull request targeting `develop` only.
3. **Independent QA and Merge Advisory** — reviews tickets in `For QA`, validates correctness and engineering quality, assesses merge risks, and gives the user an evidence-backed recommendation.

The completed product must support real execution through Codex, OpenAI API models, other coding agents, deterministic automation, and human operators behind shared provider interfaces.

Simulation-first is the delivery strategy for the MVP. It is not the final product boundary.

---

## 2. Final User Journey

```text
Login
 -> Create Project
 -> Configure Project
 -> Connect Notion
 -> Configure Repository
 -> Upload Documentation
 -> Review and Approve Documentation
 -> Click "Start this Project"
 -> Run Project Preflight
 -> Layer 1 Generates Roadmap and Tickets
 -> Human Approves Development Start
 -> Layer 2 Selects and Executes Work
 -> Pull Request Targets develop
 -> Layer 3 Performs Independent QA
 -> User Receives Merge Recommendation and Risks
 -> Human or Policy Gate Decides Merge
 -> Continue Until Project Completion
```

Every workflow stage must be visible in the interactive 3D office and in a conventional accessible dashboard.

---

## 3. Source-of-Truth Model

- **Approved project documents:** architecture and engineering baseline.
- **Approved ADRs and change decisions:** authorized exceptions to the baseline.
- **Notion tickets:** task-level source of truth for scope, acceptance criteria, dependencies, status, and required evidence.
- **Repository:** implemented code, tests, configuration, and versioned documentation.
- **Verified external evidence:** CI, pull-request, merge, deployment, and runtime state.

Conflicts must be surfaced. Agents must not silently invent a resolution.

---

## 4. Three-Layer Operating Model

### Layer 1 — Planning and Project Intelligence

Responsibilities:

- Scan and classify approved documents.
- Detect missing, conflicting, outdated, or unsafe instructions.
- Create a project context snapshot.
- Generate the implementation roadmap.
- Divide work into phases, milestones, tasks, and dependencies.
- Generate acceptance criteria, required evidence, risk, and reasoning level.
- Publish approved tasks to Notion idempotently.
- Produce a readiness decision: `ready`, `ready_with_risks`, `blocked`, or `human_decision_required`.
- Require human approval before development execution begins.

### Layer 2 — Development Execution

Responsibilities:

- Deterministically select the next workable Notion ticket.
- Acquire a ticket execution lease to prevent duplicate work.
- Read the ticket, approved documents, repository rules, and relevant code.
- Create an isolated workspace and branch.
- Implement or simulate the feature, bug, enhancement, or change.
- Run applicable tests, static analysis, security, and build checks.
- Commit and push the work.
- Create a pull request targeting `develop` only.
- Update the ticket to `For QA` with artifacts and evidence.

The implementation agent may not approve its own pull request.

### Layer 3 — Independent QA and Merge Advisory

Responsibilities:

- Select tickets in `For QA`.
- Validate ticket scope and acceptance criteria.
- Review the full diff, tests, CI, architecture, security, database impact, performance, maintainability, and regressions.
- Verify the target branch is `develop`.
- Produce a standardized merge-risk assessment.
- Return one decision: `merge_ready`, `merge_ready_with_risks`, `changes_requested`, `blocked`, or `human_review_required`.
- Notify the user and present actionable evidence.
- Never merge to `main`.

---

## 5. Deterministic Core Architecture

```text
Interactive 3D Office + Accessible Dashboard
                  |
                  v
             Application API
                  |
                  v
       Deterministic Workflow Engine
          |-- State Machine
          |-- Policy Engine
          |-- Approval Engine
          |-- Task Router
          |-- Ticket Selector and Lease Manager
          |-- Reasoning-Level Resolver
          |-- Provider Registry
          |-- Evidence Validator
          |-- Retry and Recovery Manager
          |-- Notification Dispatcher
          |-- Audit Logger
                  |
       +----------+-----------+-----------+
       |                      |           |
   Simulation              Codex       Human / Other
   Provider                Provider     Providers
                  |
       +----------+-----------+-----------+
       |                      |           |
     Notion                GitHub       CI/CD
```

The workflow engine owns truth, transitions, policy, retries, and approvals. AI agents advise or execute inside those boundaries.

---

## 6. Simulation-First MVP Rule

The MVP must execute the complete product workflow while replacing external engineering actions with clearly labeled simulation outputs.

### Real in the MVP

- Authentication
- Project creation and configuration
- Document upload, review, approval, and versioning
- Project preflight and **Start this Project** command
- Workflow engine and state transitions
- Layer 1 planning workflow
- Roadmap, phases, dependencies, and tickets
- Real Notion ticket publication
- Human approval gates
- Deterministic next-ticket selection
- Layer 2 simulated execution
- Layer 3 simulated independent QA
- Merge-risk recommendations
- In-app notifications
- Audit logs and evidence records
- Interactive 3D office and accessible dashboard
- Retry, blocker, failure, and recovery scenarios
- Usage and estimated cost tracking

### Simulated in the MVP

- Source-code modification
- Local command execution
- Commits and branch pushes
- Real GitHub pull-request creation
- CI runs
- Real QA execution against code
- Merge operations
- Deployments

A simulated artifact can advance workflow testing but can never be recorded as verified implementation, CI, review, merge, or deployment evidence.

---

## 7. Project Configuration Baseline

Every project must define:

- Name, description, project type, and ownership
- Technology stack
- Repository provider, repository URL, default branch, and integration branch
- Integration branch fixed to `develop` by default
- Notion workspace and project database
- Required documents and approval policy
- Build, test, lint, static-analysis, and security commands
- Execution provider preferences
- Default reasoning level
- Budget and retry limits
- Autonomy level
- Approval policy
- Notification preferences

The MVP may store repository configuration without performing real repository writes.

---

## 8. Start Project Contract

`StartProject` must be an idempotent command that:

1. Authorizes the user.
2. Verifies project configuration.
3. Verifies the Notion connection.
4. Confirms required documents are approved.
5. Confirms no active start execution already exists.
6. Creates a versioned project context snapshot.
7. Creates the workflow execution.
8. Starts Layer 1.
9. Records an immutable audit event.
10. Publishes progress events for the 3D office.

Repeated requests with the same idempotency key must not create duplicate roadmaps or tickets.

---

## 9. Ticket Selection Baseline

A ticket is workable only when:

```text
status is Ready or approved Changes Requested
AND all dependencies are Done
AND no unresolved blocker exists
AND no active execution lease exists
AND required documents and approvals exist
AND project budget and policy allow execution
AND an eligible provider is available
```

Ranking precedence:

1. Approved roadmap order
2. Dependency critical path
3. Priority
4. Explicit sequence
5. Risk policy
6. Age
7. Estimated effort

Selection and lease acquisition must be atomic.

---

## 10. Repository and Pull-Request Policy

- `develop` is the only normal pull-request target.
- Agents must never create a pull request to `main`.
- Agents must never push directly to protected branches.
- Every implementation uses an isolated branch and workspace.
- One ticket maps to one active implementation branch unless an approved exception exists.
- Every pull request references its Notion ticket and execution ID.
- The implementation agent cannot self-approve.
- Real merges require verified evidence and an authorized merge gate.
- The MVP represents repository operations as simulated artifacts only.

---

## 11. Merge Advisory Baseline

Every QA result must contain:

- Decision
- Confidence
- Ticket scope status
- Acceptance-criteria status
- CI status
- Test status
- Architecture status
- Security status
- Database impact
- Performance impact
- Regression risk
- Rollback complexity
- Unresolved findings
- Known merge risks
- Recommended action
- Evidence references

The user must be able to choose:

- Approve merge
- Request changes
- Escalate to human review
- Defer

For the MVP, these decisions affect simulated workflow state only.

---

## 12. Interactive 3D Office Baseline

The 3D office is a primary product interface, not decorative animation.

Required zones:

- Planning Room — Layer 1
- Development Floor — Layer 2
- QA Laboratory — Layer 3
- Approval Room — human decisions
- Operations Area — integrations, failures, and recovery

Required agent states:

- Idle
- Reading documents
- Planning
- Waiting for approval
- Selecting ticket
- Coding or simulating implementation
- Running validation
- Creating pull request
- Reviewing
- Blocked
- Retrying
- Waiting for human input
- Completed
- Failed

Clicking an agent must show its role, ticket, provider, reasoning level, state, duration, cost, assumptions, artifacts, evidence, risks, and required action.

The 3D office consumes authoritative workflow events. It never owns business state or invents progress. A conventional accessible list/dashboard must provide equivalent functionality.

---

## 13. Reasoning-Level Policy

Supported levels:

- **Low:** mechanical, localized, reversible, and easily verified work.
- **Medium:** normal engineering tasks and standard implementation work.
- **High:** architecture, security, authorization, critical data, destructive changes, final QA, merge readiness, production incidents, and deployment decisions.

Resolution precedence:

1. Explicit approved ticket value
2. Policy-required minimum
3. Task-type default
4. Agent-role default
5. Project default
6. Fallback: Medium

Reasoning may be escalated automatically. A policy-required minimum may not be reduced.

Reasoning level is never verification evidence.

---

## 14. Evidence and State

The platform distinguishes:

- Desired state
- Reported state
- Observed state
- Actual state

Artifacts are classified as:

- Assumption
- Proposal
- Simulated output
- Reported evidence
- Observed evidence
- Verified evidence
- Rejected evidence

Example:

```text
Reported: Tests passed
Observed: No CI run exists
Actual: Unverified
```

---

## 15. Reliability and Security Baseline

Required controls:

- Idempotent transitions and external writes
- Bounded retries with backoff and jitter
- Dead-letter handling
- Cancellation and timeout handling
- Replay from safe checkpoints
- Duplicate prevention for tickets, branches, PRs, comments, merges, and deployments
- Server-side authorization
- Least-privilege connector permissions
- Encrypted credentials
- Secret redaction
- Tenant and project isolation
- Immutable audit records
- Prompt-injection resistance for uploaded documents
- Provider outputs treated as untrusted input
- Protected-branch enforcement
- Signed webhook verification when webhooks are introduced

---

## 16. Final MVP Scope

The MVP is complete when a user can:

1. Log in.
2. Create and configure a project.
3. Connect Notion.
4. Upload and approve source-of-truth documents.
5. Click **Start this Project**.
6. Watch Layer 1 generate a roadmap, phases, and Notion tickets.
7. Approve or reject development readiness.
8. Watch Layer 2 select and simulate the next workable ticket.
9. Watch a simulated branch, commit, push, and pull request to `develop` be produced as labeled artifacts.
10. Watch Layer 3 perform simulated independent QA.
11. Receive a merge recommendation with risks and evidence.
12. Make a simulated merge decision.
13. Inspect the complete process in the 3D office, dashboard, timeline, and audit log.
14. Run happy-path, failure-path, and retry scenarios without duplicate side effects.

---

## 17. Delivery Progression

1. **MVP — Full workflow simulation**
2. **Layer 1 productionization — real document analysis and planning**
3. **Layer 2 productionization — real coding provider, repository writes, tests, commits, pushes, and PRs**
4. **Layer 3 productionization — real independent QA and evidence-backed merge advisory**
5. **Controlled autonomy — policy-approved low-risk merges to `develop`**
6. **Release and deployment orchestration**

The workflow engine, data model, approvals, audit system, notifications, and 3D office must not require redesign during this progression.

---

## 18. Non-Negotiable Decisions

- Simulation-first, not simulation-only.
- The final outcome is the three-layer operating model described above.
- The workflow engine is deterministic infrastructure.
- Agents operate inside explicit policy boundaries.
- Notion is the task-level source of truth.
- Approved documents are the architecture and engineering baseline.
- `develop` is the only normal PR target; never `main`.
- Layer 3 is independent from Layer 2.
- Human approval is mandatory before initial development execution in the MVP.
- Real merge requires verified evidence.
- Simulated outputs are never verified evidence.
- The interactive 3D office visualizes authoritative workflow state.
- Equivalent accessible non-3D controls are required.
