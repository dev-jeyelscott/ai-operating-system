# AI Operating System user guide

## Product overview

AI Operating System helps a team plan, simulate delivery, and independently assess software work. Layer 1 produces a roadmap, Layer 2 simulates execution, and Layer 3 independently evaluates it. Accounts, approvals, records, and Notion publication are real MVP capabilities. Synthetic branches, commits, pull requests, CI evidence, merges, and deployments remain simulated and unverified.

## Authentication and organization access

Sign in, select your organization, and create a project. Organizations and projects are isolated; permissions control who can configure integrations, approve work, and make merge decisions. Sign out from the account menu. A permission-denied response means the selected organization, project, or role is not authorized; switch context or request an authorized user to act.

## Create a project

During setup provide identity, technology stack, repository metadata, the `develop` integration branch, validation commands, policies, retry/budget limits, notification preferences, and Notion configuration. Do not put secrets in repository metadata or documents.

## Connect Notion

Share the approved database with the Notion integration, choose its workspace and database, then run the connection test. The setup screens identify missing access, invalid credentials, unavailable providers, and incompatible database configuration without showing credential secrets. Reconnect after correcting the root cause, then reconcile rather than manually creating duplicate pages.

## Documents

Upload required documents and wait for processing. Review unsafe-instruction warnings, approve or reject the submitted version, and use supersession rather than editing history. Version history and required-document checks explain why a context snapshot or start is blocked.

## Start Project

Start Project runs preflight against approved configuration and documents. It is idempotent: a repeated click or replay returns the existing result rather than starting a duplicate workflow. Capture the displayed execution identifier when requesting support.

## Planning and roadmap

Layer 1 identifies gaps/conflicts, creates phases, milestones, dependencies, risks, and traceability, then waits for human approval or rejection.

## Notion publication

After approval, publication reports created, updated, skipped, failed, and conflicted Notion tickets. Retries target failures and reconciliation preserves one stable external key per page.

## Development simulation

Layer 2 selects only an eligible ticket, obtains a lease, creates simulated planning/change/validation artifacts, and targets `develop`. Failures and retries remain visible. Nothing in this flow writes a repository or creates a real pull request.

## QA and merge advisory

Layer 3 is independent from Layer 2 and relates findings to acceptance criteria, evidence status, and merge-risk dimensions. It can request changes and require a new assessment.

## Merge decision

A human may approve, request changes, escalate, or defer the terminal merge decision. The product never performs a real merge.

## Dashboard

The dashboard shows active workflows, agents, approvals, blockers, recovery, notifications, usage, estimated costs, and audit timeline.

## 3D office

The 3D office shows the same state using rooms and agent indicators. Use keyboard controls, reduced motion, quality presets, or the WebGL fallback as needed; the accessible dashboard remains equivalent when 3D is unavailable.

## Troubleshooting

For no workable ticket, missing/conflicting documents, Notion failure, stuck execution, changes requested, or reconnection, follow the displayed blocker and the linked [runbooks](../runbooks/README.md). For 3D initialization failure, use the dashboard fallback. Never retry a consequential external operation until its root cause and existing mapping are understood.

## Guided verification

A non-author should complete login, project and Notion setup, document approval, preflight, roadmap approval, publication, development simulation, QA, merge decision, dashboard, and office inspection. Record unclear labels, required developer explanation, missing states, navigation mismatches, accessibility issues, and screenshots or Playwright traces in release evidence.
