# AI Operating System user guide

## Product overview

AI Operating System helps a team plan, simulate delivery, and independently
assess software work.

- Layer 1 produces a roadmap.
- Layer 2 simulates implementation work.
- Layer 3 independently evaluates the simulated result.

Accounts, approvals, records, workflow state, and Notion publication are real
MVP capabilities.

Synthetic branches, commits, pull requests, CI evidence, merges, and
deployments remain simulated and unverified.

## Authentication and organization access

Sign in and select the required organization.

Organizations and projects are isolated. Permissions determine who can:

- configure a project;
- configure integrations;
- approve documents and roadmaps;
- start a project;
- inspect operational state;
- make merge decisions.

A permission-denied response means the selected organization, project, or role
is not authorized. Switch to the correct organization or ask an authorized user
to perform the operation.

## Create and configure a project

Create a project and complete its setup.

Provide:

- project identity and description;
- technology stack;
- repository metadata;
- `develop` as the integration branch;
- deterministic validation commands;
- provider and reasoning policies;
- retry and budget limits;
- approval and autonomy policies;
- notification preferences;
- Notion configuration.

Do not place credentials or secrets in repository metadata, project documents,
ticket content, or support screenshots.

## Connect Notion

Share the approved Notion database with the configured integration.

Select the workspace and database, then run the connection test.

The setup screens identify:

- missing access;
- invalid credentials;
- unavailable providers;
- incompatible database configuration;
- incomplete required properties.

Credentials are not shown after storage.

After correcting a failure, reconnect and reconcile existing mappings. Do not
manually create replacement pages that could duplicate an internal task.

## Documents

Upload the required source documents and wait for processing to complete.

Review:

- document classification;
- parsing status;
- unsafe-instruction warnings;
- version checksum;
- required-document coverage;
- supersession history.

Approve or reject the submitted version. Use supersession rather than changing
historical approved content.

Only approved document versions become part of an authoritative project context
snapshot.

## Start this Project

The project details page displays **Start this Project** only when:

- the current user is authorized;
- the project is not archived;
- the project is in the `Ready for Planning` state;
- the deterministic start preconditions allow submission.

Select **Start this Project**.

While the request is being submitted, the button displays
**Starting project...** and is disabled.

After the request completes:

1. Remain on or return to the project details page.
2. Open **Operational dashboard**.
3. Confirm that the project or workflow has entered planning or another
   expected guarded state.
4. Inspect any blocker or approval request shown by the dashboard.
5. Do not repeatedly submit the command to work around a visible blocker.

The Start Project command is idempotent. A replay with the same idempotency
contract returns the existing result instead of creating another workflow,
snapshot, or roadmap.

### Information to provide when requesting support

The current project page does not display an execution identifier.

Provide the following instead:

- organization name;
- project name;
- project URL;
- approximate date and time when **Start this Project** was selected;
- current project status;
- visible blocker or exact error message;
- screenshot without credentials or sensitive document content;
- whether the button changed to **Starting project...**;
- relevant operational-dashboard state.

An authorized operator can correlate those details with audit and workflow
records.

## Planning and roadmap

Layer 1 scans the approved project context and identifies:

- missing information;
- document conflicts;
- phases and milestones;
- task dependencies;
- risks;
- acceptance criteria;
- required evidence;
- source-document traceability;
- readiness status.

The roadmap waits for an authorized human approval or rejection before ticket
publication.

## Notion publication

After roadmap approval, publication reports:

- created tickets;
- updated tickets;
- skipped tickets;
- failed tickets;
- conflicted tickets.

Retries target failed writes. Reconciliation preserves one stable external key
per internal task.

Review a conflict before retrying. Do not manually create duplicate pages.

## Development simulation

Layer 2 selects only an eligible ticket and obtains an execution lease.

It produces clearly labelled simulated artifacts such as:

- an implementation plan;
- changed-file manifest;
- synthetic diff summary;
- validation output;
- synthetic branch and commit;
- synthetic pull request targeting `develop`.

Nothing in the MVP development simulation modifies a repository or creates a
real pull request.

Validation failure prevents the ticket from moving to QA.

## QA and merge advisory

Layer 3 is independent from Layer 2.

It evaluates:

- ticket scope;
- acceptance criteria;
- architecture;
- security;
- database impact;
- performance;
- maintainability;
- regression risk;
- rollback complexity;
- evidence completeness.

Blocking findings prevent merge approval regardless of provider
recommendations.

## Merge decision

An authorized human can:

- approve;
- request changes;
- escalate;
- defer.

The MVP records a simulated merge decision. It never performs a real repository
merge.

## Operational dashboard

The operational dashboard displays authoritative:

- workflow state;
- logical agents;
- ticket state;
- blockers;
- retries;
- pending approvals;
- recent decisions;
- estimated usage and cost;
- links to relevant recovery and decision screens.

Use the dashboard when WebGL or the 3D office is unavailable.

## 3D office

The 3D office projects the same backend workflow state through rooms, agents,
indicators, and inspectors.

Use:

- keyboard controls;
- reduced-motion settings;
- rendering-quality presets;
- the accessible dashboard fallback.

No business operation depends on rendering frame rate or local animation state.

## Troubleshooting

For missing documents, conflicts, publication failure, a stuck lease,
dead-letter recovery, integration outage, projection rebuild, or backup and
restore, follow the relevant [operational runbook](../runbooks/README.md).

Do not retry a consequential external action until the root cause and existing
external mapping have been inspected.

## Guided verification

A non-author should verify the following journey:

1. Sign in and select an organization.
2. Create and configure a project.
3. Connect Notion.
4. Upload and approve documents.
5. Select **Start this Project**.
6. Confirm planning through **Operational dashboard**.
7. Review and approve the roadmap.
8. Inspect Notion publication.
9. Inspect Layer 2 simulation.
10. Inspect independent Layer 3 QA.
11. Perform a simulated merge decision.
12. Compare the dashboard and 3D office.
13. Exercise keyboard, reduced-motion, and WebGL-fallback paths.

Record unclear labels, navigation mismatches, inaccessible interactions,
incorrect support instructions, screenshots, and Playwright traces in the
candidate evidence.
