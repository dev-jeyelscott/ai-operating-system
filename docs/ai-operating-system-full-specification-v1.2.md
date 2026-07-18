# AI Operating System — Final Product and Technical Specification

**Version:** 1.2  
**Status:** Final approved architecture baseline  
**Updated:** 2026-07-18  
**Owner:** Product Owner / System Architect

---

## 1. Executive Summary

The AI Operating System is a controlled software-development orchestration platform that presents an AI engineering organization through an interactive 3D office.

The product converts approved project documentation into a real, auditable delivery workflow. A user creates and configures a project, uploads documentation, and selects **Start this Project**. The system then coordinates planning agents, development agents, independent QA agents, deterministic policy, human approvals, Notion tickets, repositories, pull requests, validation evidence, and merge decisions.

The MVP is simulation-first. It proves the entire workflow, user experience, state model, approvals, retries, integrations, and 3D visualization before real coding agents are allowed to modify repositories. The final product replaces simulated activities incrementally with real provider execution without redesigning the workflow engine or user experience.

---

## 2. Product Vision

The completed product behaves like a software-development organization with three operational layers:

```text
User starts project
      |
      v
Layer 1: Planning and Project Intelligence
      |
      v
Approved roadmap and Notion tickets
      |
      v
Layer 2: Development Execution
      |
      v
Pull request to develop
      |
      v
Layer 3: Independent QA and Merge Advisory
      |
      v
Human or policy-controlled merge decision
```

The interactive office shows where agents are working, what ticket they own, which provider is executing the role, what evidence exists, what is blocked, and what decision the user must make.

---

## 3. Product Goals

The platform must:

1. Authenticate users and isolate their organizations and projects.
2. Allow users to create and configure software projects.
3. Accept, version, review, and approve project source-of-truth documents.
4. Validate project readiness before execution.
5. Generate roadmaps, phases, milestones, dependencies, risks, and acceptance criteria.
6. Publish approved task-level tickets to Notion idempotently.
7. Select the next workable ticket deterministically.
8. Delegate work to logical agents and execution providers.
9. Support features, bugs, enhancements, maintenance, and approved changes.
10. Create isolated implementation branches and pull requests targeting `develop` only when real execution is enabled.
11. Perform independent QA and merge-risk analysis.
12. Help the user decide whether a pull request should be merged.
13. Preserve assumptions, evidence, confidence, cost, provenance, and complete audit history.
14. Present workflow state through a 3D office and an accessible conventional dashboard.
15. Evolve from simulation to real and hybrid execution without replacing core architecture.

---

## 4. Product Principles

### 4.1 Deterministic workflow, probabilistic workers

AI providers may interpret or execute work, but deterministic application infrastructure owns workflow truth, allowed transitions, authorization, policy, retries, and approvals.

### 4.2 Simulation-first, not simulation-only

The MVP uses simulation to validate the final operating model. Simulation is a provider implementation, not a separate throwaway product.

### 4.3 Evidence over claims

Agent claims are not completion evidence. Verified external evidence determines actual state.

### 4.4 Independent review

The provider execution that performs Layer 2 may not be the sole reviewer or approver in Layer 3.

### 4.5 Human control at consequential gates

The user retains control over roadmap approval, high-risk changes, merge decisions, and production-impacting operations unless a later approved policy explicitly delegates a low-risk operation.

### 4.6 Least privilege

Each connector and provider receives only the permissions required for its current operation.

### 4.7 Accessible equivalence

The 3D office must have an equivalent accessible dashboard, timeline, and action interface.

---

## 5. Canonical User Journey

### 5.1 Project onboarding

```text
Login
 -> Create Project
 -> Configure Details
 -> Configure Notion
 -> Configure Repository
 -> Configure Policies and Commands
 -> Upload Documents
 -> Review Documents
 -> Approve Documents
```

### 5.2 Project start

```text
Click "Start this Project"
 -> Authorize request
 -> Validate required configuration
 -> Verify document approvals
 -> Create project context snapshot
 -> Start Layer 1
 -> Display progress in 3D office
```

### 5.3 Planning and approval

```text
Scan documents
 -> Detect gaps and conflicts
 -> Generate roadmap
 -> Generate phases and tasks
 -> Assign dependencies, risks, agents, and reasoning
 -> Produce readiness decision
 -> User approves
 -> Publish Notion tickets
```

### 5.4 Development loop

```text
Select next workable ticket
 -> Acquire execution lease
 -> Plan implementation
 -> Execute or simulate
 -> Validate
 -> Commit and push or simulate
 -> Create PR to develop or simulate
 -> Update ticket to For QA
```

### 5.5 QA and merge decision

```text
Select For QA ticket
 -> Independent review
 -> Validate evidence
 -> Assess architecture, security, quality, performance, and regressions
 -> Produce merge-risk report
 -> Notify user
 -> Approve, request changes, escalate, or defer
```

The loop continues until no workable tickets remain or the project is paused, blocked, cancelled, or completed.

---

## 6. Source-of-Truth and Precedence

### 6.1 Approved project documents

Approved documents define architecture and engineering constraints, including requirements, technical design, data design, API contracts, security rules, testing standards, deployment rules, and ADRs.

### 6.2 Notion tickets

Notion is the task-level source of truth for objective, scope, acceptance criteria, dependencies, risk, required evidence, assigned role, execution status, pull request, QA findings, and final disposition.

### 6.3 Repository

The repository is authoritative for implemented code, tests, configuration, migrations, CI definitions, and versioned documentation.

### 6.4 Verified external systems

GitHub, CI, deployment platforms, and runtime monitors establish observed and verified state.

### 6.5 Precedence

1. Approved project documents
2. Approved ADRs and change decisions
3. Notion ticket
4. Repository implementation
5. Execution logs and generated artifacts

A conflict must produce a blocker or human-decision request. It must not be silently resolved.

---

## 7. Target Operating Model

### 7.1 Layer 1 — Planning and Project Intelligence

Logical roles may include Product Manager, Business Analyst, Project Manager, Software Architect, Security Architect, Database Architect, and Documentation Analyst.

Required inputs:

- Approved project context snapshot
- Project configuration
- Existing roadmap and ticket state
- Organization policies

Required outputs:

- Document inventory and classification
- Missing-information report
- Conflict and ambiguity report
- Architecture and security concerns
- Roadmap
- Phases and milestones
- Task decomposition
- Dependencies and critical path
- Acceptance criteria
- Required evidence
- Risk and reasoning classification
- Suggested logical agent
- Estimated complexity
- Project-readiness decision

Readiness decisions:

- `ready`
- `ready_with_risks`
- `blocked`
- `human_decision_required`

Layer 1 may recommend that development start, but deterministic policy and the user approval gate authorize it.

### 7.2 Layer 2 — Development Execution

Logical roles may include Backend Engineer, Frontend Engineer, Database Engineer, Test Engineer, DevOps Engineer, and Documentation Engineer.

Required inputs:

- Claimed Notion ticket
- Approved project context snapshot
- Current repository state or simulated repository context
- Project commands and engineering policies
- Provider and reasoning decision

Required outputs:

- Implementation plan
- Files expected to change
- Assumptions
- Implementation or simulated implementation artifact
- Tests added or simulated test plan
- Validation results or simulated results
- Branch record
- Commit record
- Pull-request record targeting `develop`
- Known limitations and risks
- Evidence manifest
- Updated Notion status

The Layer 2 actor cannot approve its own output.

### 7.3 Layer 3 — Independent QA and Merge Advisory

Logical roles may include QA Engineer, Code Reviewer, Security Reviewer, Architecture Reviewer, Database Reviewer, Performance Reviewer, and Merge Advisor.

Required inputs:

- Full Notion ticket
- Approved project context
- Implementation plan
- Pull-request diff or simulated diff
- Tests and CI evidence
- Security and architecture rules

Required outputs:

- Acceptance-criteria traceability
- Functional correctness assessment
- Architecture alignment assessment
- Security assessment
- Database and migration assessment
- Performance and optimization assessment
- Maintainability assessment
- Regression assessment
- CI and evidence verification
- Merge-risk assessment
- Recommended disposition

Layer 3 decisions:

- `merge_ready`
- `merge_ready_with_risks`
- `changes_requested`
- `blocked`
- `human_review_required`

---

## 8. Core Architecture

The MVP should be implemented as a modular monolith to minimize distributed-system complexity while preserving clean boundaries.

### 8.1 Recommended implementation stack

- **Application:** Laravel 13 modular monolith
- **UI:** React + TypeScript through Inertia
- **3D UI:** React Three Fiber and Drei
- **UI components:** Tailwind CSS and shadcn/ui-compatible components
- **Database:** PostgreSQL
- **Queue and locking:** Redis with Laravel Horizon
- **Real-time updates:** Laravel Reverb or Server-Sent Events; transport abstracted behind an event stream interface
- **Object storage:** S3-compatible storage for documents and artifacts
- **Authentication:** Laravel authentication with organization/project authorization policies
- **Testing:** PHPUnit or Pest for backend; Vitest and React Testing Library for frontend; Playwright for critical user journeys
- **Packaging:** Docker Compose for local development

### 8.2 Component model

```text
React / Inertia Application
  |-- Project Setup
  |-- Document Center
  |-- Roadmap and Tickets
  |-- Approvals
  |-- 3D Office
  |-- Accessible Dashboard
  |-- Execution Inspector
  |-- Audit Timeline
             |
             v
Laravel Application API and Commands
  |-- Identity and Organization Module
  |-- Projects Module
  |-- Documents Module
  |-- Integrations Module
  |-- Planning Module
  |-- Workflow Module
  |-- Tickets Module
  |-- Execution Module
  |-- QA and Merge Advisory Module
  |-- Evidence Module
  |-- Notifications Module
  |-- Audit Module
             |
             v
PostgreSQL + Redis + Object Storage
```

The modules must communicate through application commands, domain events, and stable interfaces rather than direct cross-module table manipulation.

---

## 9. Workflow Engine

The workflow engine is deterministic application infrastructure.

It owns:

- Workflow definitions and versions
- State transitions
- Transition guards
- Project and ticket leases
- Approvals
- Provider selection
- Reasoning resolution
- Retry policy
- Timeout and cancellation
- Idempotency
- Evidence requirements
- Escalation
- Audit events

An AI Orchestrator may propose plans or classifications but cannot bypass transition guards.

### 9.1 Result-driven handoffs

A handoff occurs only after required outputs exist.

Example:

```text
Layer 2 reports implementation complete
 -> branch artifact exists
 -> validation artifact exists
 -> PR artifact targets develop
 -> ticket status may move to For QA
```

Elapsed time alone must never advance a handoff.

---

## 10. Project Model and Configuration

Required project fields:

| Field | Purpose |
|---|---|
| Name | Human-readable project name |
| Slug | Stable identifier |
| Description | Product context |
| Project Type | Web app, API, library, service, mobile, other |
| Technology Stack | Frameworks, languages, databases, infrastructure |
| Repository Provider | GitHub initially |
| Repository URL | Repository reference |
| Default Branch | Usually `main` |
| Integration Branch | Must default to `develop` |
| Notion Workspace | Integration target |
| Notion Database | Ticket database |
| Required Documents | Start-gate requirements |
| Build Command | Deterministic validation command |
| Test Command | Automated tests |
| Lint Command | Formatting and lint checks |
| Static Analysis Command | Type or static analysis |
| Security Command | Dependency or security checks |
| Default Reasoning | Low, Medium, or High |
| Provider Policy | Allowed providers and fallback order |
| Budget | Project execution budget |
| Retry Limit | Maximum automatic attempts |
| Autonomy Level | Advisory, approval-required, policy-controlled |
| Notification Policy | In-app and future channels |

Credentials must be stored separately using encrypted secret storage and least-privilege authorization.

---

## 11. Document Ingestion and Approval

### 11.1 Supported document classes

- Product charter
- Requirements
- Architecture and technical design
- Security baseline
- Database design
- API contracts
- Testing strategy
- Deployment and operations guides
- ADRs
- Existing roadmap
- Coding standards

### 11.2 Processing states

```text
Uploaded
 -> Scanning
 -> Parsed
 -> Needs Review
 -> Approved
 -> Superseded
 -> Rejected
```

### 11.3 Safety rules

- Treat uploaded text as untrusted data, not privileged instructions.
- Separate system policy from document content.
- Detect attempts to override workflow, exfiltrate secrets, or bypass approvals.
- Redact secrets and sensitive values before sending context to providers.
- Record parser version, checksum, and document version.
- Require approval before a document becomes part of the authoritative context snapshot.

---

## 12. Start This Project Command

`StartProject` is a deterministic, idempotent application command.

### 12.1 Preconditions

- User is authorized for the project.
- Project status allows start.
- Required configuration is complete.
- Notion integration is valid.
- Required documents are approved.
- No active start execution exists.
- Project budget and policy permit planning.

### 12.2 Effects

- Create a workflow execution.
- Create an immutable project context snapshot.
- Record input document versions and checksums.
- Resolve workflow version.
- Emit `project.start_requested`.
- Start Layer 1.
- Emit audit and notification events.
- Return the execution ID.

### 12.3 Idempotency

The same project, context version, and idempotency key must return the existing execution. It must not duplicate roadmaps, phases, tasks, Notion tickets, or approvals.

---

## 13. Roadmap and Task Model

A roadmap contains:

- Goal
- Scope
- Assumptions
- Constraints
- Risks
- Phases
- Milestones
- Tasks
- Dependencies
- Critical path
- Definition of done
- Required approvals

Ticket types:

- Feature
- Bug
- Enhancement
- Change
- Technical debt
- Security
- Documentation
- Infrastructure
- Investigation

Every task requires:

- Objective
- Scope and exclusions
- Acceptance criteria
- Dependencies
- Priority
- Risk level
- Required AI Model Reasoning
- Suggested logical agent
- Required evidence
- Human approval requirement
- Estimated effort or complexity
- Source document references

---

## 14. Notion Integration

Notion remains the task-level source of truth.

### 14.1 Required ticket properties

| Property | Type |
|---|---|
| Ticket ID | Unique ID |
| Name | Title |
| Type | Select |
| Status | Status |
| Phase | Select or relation |
| Roadmap Order | Number |
| Priority | Select |
| Risk Level | Select |
| Required AI Model Reasoning | Low / Medium / High |
| Logical Agent | Select |
| Execution Provider | Select |
| Repository | URL or relation |
| Branch | Text |
| Pull Request | URL |
| Dependencies | Relation |
| Human Approval Required | Checkbox |
| Execution Attempt | Number |
| Last Execution Result | Select |
| Blocker | Text |
| Actual State | Select |
| Confidence | Number |
| Workflow Execution ID | Text |

Ticket body content must include objective, scope, exclusions, acceptance criteria, required evidence, source references, risks, implementation notes, QA findings, and final disposition.

### 14.2 Idempotency

Each internal task has a stable external key. Publishing or retrying must upsert the corresponding Notion page rather than create a duplicate.

---

## 15. Ticket Status Model

Recommended statuses:

- Backlog
- Ready
- In Progress
- Blocked
- For QA
- Changes Requested
- Approved for Merge
- Done
- Cancelled

Allowed transitions must be defined by workflow policy. External Notion changes must be reconciled and audited before internal state changes.

---

## 16. Next Workable Ticket Selection

### 16.1 Eligibility

```text
status in [Ready, approved Changes Requested]
AND all hard dependencies are Done
AND blocker is empty or resolved
AND required approvals exist
AND no active execution lease exists
AND project is active
AND provider capability is available
AND budget and retry policy permit execution
```

### 16.2 Ranking

1. Approved roadmap order
2. Dependency critical path
3. Priority
4. Explicit sequence
5. Risk policy
6. Oldest ready timestamp
7. Lowest estimated effort as tie breaker

### 16.3 Concurrency control

Selection and lease creation must occur in one database transaction. The lease records ticket, execution, owner, acquired time, expiry, heartbeat, and release reason.

An expired lease may be reclaimed only after recovery policy confirms the previous execution is no longer active.

---

## 17. Repository Execution Policy

### 17.1 Branch policy

- Default branch may be `main`.
- Integration branch is `develop`.
- All normal automated pull requests target `develop` only.
- No agent may open or merge a pull request to `main`.
- No agent may push directly to a protected branch.
- One ticket maps to one active branch unless approved otherwise.

Suggested branch names:

```text
feature/{ticket-id}-{slug}
fix/{ticket-id}-{slug}
enhancement/{ticket-id}-{slug}
change/{ticket-id}-{slug}
chore/{ticket-id}-{slug}
```

### 17.2 Workspace isolation

Each execution receives an isolated workspace, immutable base commit reference, branch, environment, execution ID, and cleanup policy.

### 17.3 Pull-request requirements

A real pull request must include:

- Notion ticket link
- Execution ID
- Objective and implementation summary
- Files and modules changed
- Tests and commands run
- Evidence links
- Known risks
- Rollback notes
- Target branch confirmation

### 17.4 Self-review restriction

The Layer 2 provider may produce a self-check but cannot be the sole Layer 3 approval authority.

---

## 18. QA and Merge Advisory Contract

Every QA execution must produce a structured result:

```json
{
  "decision": "merge_ready_with_risks",
  "confidence": 0.88,
  "target_branch": "develop",
  "ticket_scope_satisfied": true,
  "acceptance_criteria_verified": true,
  "ci_status": "passed",
  "test_status": "passed",
  "architecture_status": "passed",
  "security_status": "passed",
  "database_impact": "none",
  "performance_impact": "low",
  "regression_risk": "low",
  "rollback_complexity": "low",
  "unresolved_findings": [],
  "merge_risks": [],
  "recommendation": "Approve merge into develop after human confirmation.",
  "evidence_ids": []
}
```

### 18.1 Required review dimensions

- Functional correctness
- Scope and acceptance criteria
- Architectural alignment
- Authorization and security
- Data integrity and migrations
- Performance and query behavior
- Maintainability and clarity
- Test coverage and negative cases
- CI and static-analysis status
- Dependency and supply-chain risk
- Backward compatibility
- Regression risk
- Rollback feasibility
- Observability and operational impact

### 18.2 Merge gate

A real merge requires:

- Correct target branch
- Verified required evidence
- Passing required CI checks
- Resolved blocking findings
- Approved security and architecture checks
- Authorized human or deterministic merge approval

Simulated QA or CI can never authorize a real merge.

---

## 19. User Decisions and Notifications

Required notifications:

- Documentation scan completed
- Missing or conflicting requirements found
- Roadmap ready for approval
- Project ready or blocked
- Ticket selected
- Ticket blocked or retrying
- Simulated or real pull request created
- CI failed
- QA requested changes
- Merge recommendation ready
- Human decision required
- Merge succeeded or failed

Merge-decision notifications must show:

- Ticket and pull request
- Change summary
- Validation and CI status
- Security and architecture findings
- Regression risk
- Rollback complexity
- Known risks
- Recommendation
- Evidence links
- Actions: Approve, Request Changes, Escalate, Defer

The MVP provides in-app notifications. Email, Slack, and other channels are future adapters.

---

## 20. Interactive 3D Office

### 20.1 Purpose

The 3D office is the primary visual representation of the organization. It must communicate real workflow state rather than play scripted animations unrelated to the domain.

### 20.2 Required zones

- Lobby and project selector
- Planning Room
- Development Floor
- QA Laboratory
- Approval Room
- Operations Area
- Archive or Completed Work area

### 20.3 Agent state projection

Each logical agent is projected from a workflow execution or role assignment. The UI may animate agents walking or working, but the displayed state must come from the backend event stream.

Supported states:

- Idle
- Reading documents
- Planning
- Waiting for approval
- Selecting ticket
- Implementing
- Validating
- Creating pull request
- Reviewing
- Blocked
- Retrying
- Waiting for human
- Completed
- Failed

### 20.4 Interaction

Selecting an agent opens an inspector containing:

- Logical role
- Execution provider
- Reasoning level
- Project and ticket
- Current action
- Start time and duration
- Cost and token estimates
- Assumptions and confidence
- Artifacts and evidence
- Logs and state transitions
- Blockers and risks
- Required user action

### 20.5 Architecture

```text
Workflow Events
 -> Projection Builder
 -> Office Read Model
 -> Real-time Event Stream
 -> 3D Office and Accessible Dashboard
```

The 3D client never writes workflow state directly. User actions call authorized application commands.

### 20.6 Accessibility and performance

- Provide keyboard-accessible equivalent controls.
- Provide a list/table view with the same information and actions.
- Respect reduced-motion preferences.
- Lazy-load 3D assets.
- Support low-quality rendering mode.
- Degrade to the dashboard when WebGL is unavailable.
- Keep business operations independent from rendering frame rate.

---

## 21. Execution Provider Abstraction

```typescript
interface ExecutionProvider {
  id(): string;
  supports(capability: Capability): boolean;
  supportsReasoning(level: ReasoningLevel): boolean;
  execute(request: ExecutionRequest): Promise<ExecutionResult>;
  cancel(executionId: string): Promise<void>;
  getStatus(executionId: string): Promise<ExecutionStatus>;
}
```

Required provider implementations:

- `SimulationExecutionProvider`
- `CodexExecutionProvider`
- `OpenAIExecutionProvider`
- `HumanExecutionProvider`

Provider-specific logic must remain outside the workflow domain.

### 21.1 Provider request

A request must contain a stable execution ID, capability, project context snapshot, task context, policy constraints, requested reasoning, evidence requirements, allowed tools, timeouts, and budget.

### 21.2 Provider result

A result must contain status, provider identity, actual capabilities used, artifacts, claims, evidence references, assumptions, confidence, risks, cost, timestamps, and recommended next action.

---

## 22. Simulation Provider

### 22.1 Modes

- Deterministic
- Seeded variable
- Happy path
- Failure path
- Chaos path

### 22.2 Required scenarios

- Successful roadmap generation
- Missing documentation
- Conflicting architecture rules
- Notion publication failure and retry
- No workable ticket
- Ticket dependency blocked
- Layer 2 implementation success
- Validation failure
- Provider timeout
- PR created with wrong target and rejected
- QA pass
- QA changes requested
- Merge-ready with risks
- Human approval deferred
- Duplicate command replay

### 22.3 Non-deception

Every simulated artifact must include:

```text
execution_provider: simulation
simulation_mode
simulation_seed
assumptions
confidence
evidence_still_required
actual_state: unverified
```

The UI must visibly label simulated activities and evidence.

---

## 23. AI Reasoning-Level Policy

Supported levels:

- Low
- Medium
- High

### 23.1 Defaults

| Logical role | Default |
|---|---|
| Product Manager | Medium |
| Business Analyst | Medium |
| Project Manager | Medium |
| Software Architect | High |
| Backend Engineer | Medium |
| Frontend Engineer | Medium |
| Database Engineer | High |
| Security Engineer | High |
| QA Engineer | Medium |
| Code Reviewer | High |
| DevOps Engineer | High |
| Documentation Engineer | Low |

### 23.2 Resolution precedence

1. Explicit approved ticket value
2. Policy-required minimum
3. Task-type default
4. Agent-role default
5. Project default
6. System fallback: Medium

### 23.3 Escalation

Escalate to High for security, authorization, privacy, money, critical business data, destructive changes, architecture conflicts, non-deterministic failures, production reliability, final QA, or merge decisions.

Reasoning may not replace deterministic evidence.

For simulation:

```text
requested_reasoning_level: high
effective_reasoning_level: simulated
execution_provider: simulation
```

---

## 24. Evidence Model

Evidence classifications:

- Assumption
- Proposal
- Simulated output
- Reported evidence
- Observed evidence
- Verified evidence
- Rejected evidence

The system distinguishes:

- Desired state
- Reported state
- Observed state
- Actual state

Example:

```text
Reported: Tests passed
Observed: No CI run exists
Actual: Unverified
```

A verified evidence record includes type, provider, immutable source reference, commit SHA where applicable, claims, observation time, verification method, and expiration if evidence can become stale.

---

## 25. Domain States and Events

### 25.1 Project states

- Draft
- Configuring
- Documents Pending
- Ready for Planning
- Planning
- Awaiting Roadmap Approval
- Ready for Development
- Active
- Paused
- Blocked
- Completed
- Cancelled

### 25.2 Execution states

- Queued
- Running
- Waiting for Approval
- Waiting for Evidence
- Blocked
- Retry Scheduled
- Completed
- Failed
- Cancelled

### 25.3 Core events

```text
project.created
project.configuration_updated
document.uploaded
document.scan_started
document.approved
project.start_requested
project.context_snapshotted
planning.started
roadmap.generated
readiness.assessed
approval.requested
approval.granted
tickets.publish_started
ticket.published
ticket.selected
ticket.lease_acquired
implementation.started
validation.started
pull_request.created
qa.started
merge_assessment.completed
human_decision_required
changes.requested
merge.approved
workflow.blocked
workflow.retry_scheduled
workflow.completed
```

Events must include event ID, aggregate ID, execution ID, actor, provider, timestamp, correlation ID, causation ID, and schema version.

---

## 26. Data Model

Core entities:

- `organizations`
- `users`
- `organization_memberships`
- `projects`
- `project_integrations`
- `project_policies`
- `project_commands`
- `project_documents`
- `document_versions`
- `document_approvals`
- `project_context_snapshots`
- `roadmaps`
- `roadmap_phases`
- `tasks`
- `task_dependencies`
- `external_ticket_mappings`
- `ticket_execution_leases`
- `logical_agents`
- `execution_providers`
- `executions`
- `execution_attempts`
- `workflow_definitions`
- `workflow_instances`
- `workflow_transitions`
- `approvals`
- `artifacts`
- `evidence`
- `qa_assessments`
- `merge_decisions`
- `notifications`
- `audit_events`
- `provider_credentials`
- `usage_records`
- `policy_decisions`
- `office_projections`

Important execution fields:

```text
requested_reasoning_level
effective_reasoning_level
reasoning_resolution_source
reasoning_escalation_reason
execution_provider
model_identifier
simulation_mode
simulation_seed
reported_state
observed_state
actual_state
confidence
evidence_status
correlation_id
idempotency_key
```

---

## 27. Security Requirements

- Server-side authorization for every command and resource.
- Organization and project isolation in queries and policies.
- Least-privilege Notion and GitHub scopes.
- Encrypted provider credentials with rotation support.
- No secrets in prompts, artifacts, logs, or notifications.
- Redaction before persistence and provider dispatch.
- Immutable audit history for privileged actions.
- Explicit approval for repository writes, merges, and deployments.
- Protected-branch enforcement.
- Signed webhook validation and replay protection.
- Rate limiting and abuse controls.
- Prompt-injection resistance for documents and provider outputs.
- Provider output schema validation.
- Dependency and supply-chain scanning.
- File-type, size, and malware controls for uploads.
- Secure workspace isolation for future real code execution.

AI output must never directly execute privileged operations without deterministic authorization and policy checks.

---

## 28. Reliability and Recovery

The system must support:

- Idempotent commands and transitions
- Transactional outbox for domain events
- Idempotency keys for external writes
- Bounded retries
- Exponential backoff with jitter
- Dead-letter handling
- Cancellation
- Heartbeats and timeouts
- Lease expiration and safe reclamation
- Provider fallback where policy permits
- Manual recovery
- Replay from safe checkpoints
- Compensation for partial external writes
- Duplicate prevention for tickets, branches, PRs, comments, merges, and deployments

External side effects must store request IDs, external IDs, and reconciliation state.

---

## 29. Observability

Every execution must record:

- Organization, project, workflow, and ticket IDs
- Execution and attempt IDs
- Provider and logical role
- Requested and effective reasoning
- Model identifier
- Prompt or template version
- Input snapshot and document versions
- State transitions
- Duration and queue time
- Token or credit usage
- Cost
- Retries and errors
- Artifacts and evidence
- Human approvals
- Correlation and causation IDs

Operational views:

- Active projects and workflows
- Blocked workflows
- Agent and provider health
- Success and failure rates
- Retry rate
- Queue latency
- Average task duration
- Cost by project, provider, role, and reasoning level
- Evidence completeness
- Tickets awaiting approval
- QA outcomes
- Merge outcomes
- Simulation scenario coverage

---

## 30. Cost Controls

- Medium reasoning as project default
- Low reasoning for mechanical work
- High reasoning only where risk or ambiguity requires it
- Task decomposition
- Per-project and per-ticket budgets
- Retry and attempt limits
- Provider price policies
- Context snapshot reuse
- Deterministic result caching where safe
- Human approval before expensive re-execution
- Cost alerts and hard limits

The platform must report estimated and actual cost separately. Simulation uses estimated cost only.

---

## 31. Final MVP Definition

### 31.1 MVP objective

Demonstrate the full end-to-end product experience and validate the workflow architecture using real project management and Notion integration with simulated engineering execution.

### 31.2 Real capabilities

- Authentication and project isolation
- Project setup and validation
- Document upload, parsing, review, and approval
- Project context snapshots
- Start Project command
- Deterministic workflow engine
- Layer 1 planning simulation with structured outputs
- Roadmap and phase management
- Real Notion ticket publication
- Human roadmap approval
- Next workable ticket selection and leases
- Layer 2 development simulation
- Layer 3 QA simulation
- Merge-risk reports and human decisions
- 3D office and accessible dashboard
- In-app notifications
- Audit log, artifacts, evidence, and usage estimates
- Happy, failure, retry, blocker, and recovery scenarios

### 31.3 Simulated capabilities

- Repository modifications
- Commands and tests
- Commit and push
- Real GitHub PR creation
- CI
- QA against source code
- Merge
- Deployment

### 31.4 MVP exclusions

- Real autonomous repository writes
- Real PR merging
- Production deployment
- Multi-provider optimization
- Billing and payment collection
- Marketplace of agents
- Self-modifying workflows
- Fully autonomous high-risk decisions
- Email, Slack, or mobile notifications

---

## 32. MVP Functional Epics

1. Foundation and modular architecture
2. Authentication, organizations, and authorization
3. Project setup and policy configuration
4. Document center and approvals
5. Notion integration
6. Workflow engine, outbox, retries, and audit
7. Layer 1 planning simulation
8. Roadmap approval and ticket publication
9. Ticket selector and lease management
10. Layer 2 development simulation
11. Layer 3 QA and merge advisory simulation
12. Notifications and human decision center
13. 3D office and accessible dashboard
14. Usage and cost estimates
15. Failure scenarios, recovery, security hardening, and UAT

The detailed MVP acceptance criteria are defined in the companion MVP specification.

---

## 33. MVP Acceptance Criteria

The MVP is accepted when:

1. A user can create and configure a project.
2. Required project fields and integrations are validated.
3. Documents can be uploaded, versioned, reviewed, approved, and snapshotted.
4. **Start this Project** is idempotent.
5. Layer 1 generates a structured roadmap and readiness decision.
6. The user can approve or reject the roadmap.
7. Approved tasks are published to Notion without duplicates.
8. The selector chooses only eligible tickets and prevents duplicate claims.
9. Layer 2 produces clearly labeled simulated branch, commit, validation, and PR artifacts targeting `develop`.
10. Layer 3 independently produces a structured QA and merge-risk report.
11. The user can approve, request changes, escalate, or defer a simulated merge decision.
12. The 3D office reflects authoritative workflow events in near real time.
13. The accessible dashboard offers equivalent information and actions.
14. Simulation cannot be mistaken for verified execution.
15. Audit history reconstructs every material transition and decision.
16. Retries do not duplicate Notion tickets, workflows, executions, or decisions.
17. Happy-path and required failure scenarios pass automated tests.
18. Authorization, project isolation, secret handling, and upload controls pass security review.
19. The architecture permits real providers to replace simulation without changing workflow contracts.

---

## 34. Delivery Progression to Final Outcome

### Stage 1 — Final MVP: full workflow simulation

Build and validate the entire product lifecycle, 3D experience, policies, states, approvals, and integrations.

### Stage 2 — Real Layer 1

Enable real provider-backed document analysis, roadmap generation, conflict detection, and readiness assessment while preserving human approval.

### Stage 3 — Real Layer 2

Add secure workspace execution, real repository reads and writes, commands, tests, commits, pushes, and pull requests to `develop`.

### Stage 4 — Real Layer 3

Add independent real code review, CI evidence verification, security and architecture assessment, and merge-risk recommendations.

### Stage 5 — Controlled autonomy

Permit policy-approved low-risk merges to `develop` only after reliability metrics and audit evidence justify it. Preserve human gates for medium- and high-risk operations.

### Stage 6 — Release and operations

Add release-train orchestration, deployment gates, operational verification, rollback, and incident workflows.

---

## 35. Remaining Risks and Mitigations

| Risk | Mitigation |
|---|---|
| Simulation creates false confidence | Persistent labels, separate evidence classes, actual state remains unverified |
| Documents contain malicious instructions | Untrusted-input handling, policy separation, approval, redaction |
| Duplicate external side effects | Idempotency keys, external mappings, reconciliation, outbox |
| Two agents select one ticket | Atomic selection and lease management |
| Implementation and reviewer share blind spots | Independent Layer 3 provider and deterministic checks |
| 3D UI obscures operational detail | Equivalent dashboard, inspector, timeline, accessibility fallback |
| Human approvals become bottlenecks | Clear risk-based gates, notification center, future policy delegation |
| Provider costs grow | Budgets, reasoning policy, context reuse, retry limits |
| Real code execution becomes dangerous | Sandboxed workspace, least privilege, branch protection, staged rollout |
| Notion and repository drift | Reconciliation jobs, precedence rules, conflict escalation |

---

## 36. Final Decisions

- The final product is the three-layer autonomous development office.
- Simulation-first is the MVP strategy, not the final operating limit.
- The platform is provider-independent.
- The workflow engine is deterministic infrastructure.
- Approved documents are the engineering baseline.
- Notion is the task-level source of truth.
- Repository and external evidence establish implementation truth.
- `develop` is the only normal pull-request target; agents never target `main`.
- Layer 3 is independent from Layer 2.
- Real merges require verified evidence and an authorized gate.
- The user receives a structured merge-risk recommendation before deciding.
- The 3D office is an event-driven projection of authoritative workflow state.
- An accessible conventional interface is mandatory.
- The modular-monolith MVP uses Laravel, React, PostgreSQL, Redis, and React Three Fiber.
- Real providers must replace simulation incrementally without redesigning workflow contracts.
