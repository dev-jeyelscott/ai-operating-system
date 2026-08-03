# AI Operating System documentation

## Canonical authority hierarchy

The following hierarchy governs product, implementation, operational, and
release decisions.

1. **Approved project documents and product specifications**

   Approved product, architecture, security, data, testing, deployment, and
   operational documents define the intended product and engineering
   constraints.

2. **Approved ADRs and explicitly approved change decisions**

   Accepted architecture decision records and approved change decisions refine
   or supersede earlier decisions within their stated scope. A draft or
   proposed ADR has no authority until it is approved.

3. **Approved Notion ticket**

   The approved Notion ticket is the task-level authority for objective, scope,
   exclusions, acceptance criteria, dependencies, required evidence, risk,
   assigned role, and final disposition.

4. **Repository implementation**

   The repository is authoritative for currently implemented code, migrations,
   tests, configuration, CI workflows, operational scripts, and versioned
   documentation. Repository implementation does not silently override an
   approved specification, ADR, change decision, or ticket.

5. **Execution logs and generated artifacts**

   Execution logs, provider output, reports, simulated diffs, command output,
   and generated artifacts describe what an execution reported or produced.
   They remain subject to evidence classification and verification.

## Verification authority

Verified external systems establish observed and verified state:

- GitHub establishes repository, commit, branch, pull-request, review, and tag
  state.
- CI establishes whether required checks ran and passed for an exact commit.
- Deployment systems establish deployment state.
- PostgreSQL, Redis, object storage, Horizon, Reverb, and runtime monitoring
  establish operational state.
- Notion establishes the current externally published ticket state, subject to
  reconciliation with the approved internal record.

A provider claim or simulated artifact cannot replace verified external
evidence.

## Conflict handling

A conflict between authorities must not be silently resolved.

When a conflict is detected:

1. Stop the affected workflow transition or consequential operation.
2. Preserve both conflicting records and their provenance.
3. Record the conflict as a blocker or human-decision request.
4. Identify the highest applicable approved authority.
5. Obtain an authorized decision.
6. Update affected documents, tickets, implementation, and reconciliation state
   explicitly.
7. Preserve the decision in the audit history.

## Documentation index

| Document | Purpose | Audience | Owner | Last reviewed | Review trigger |
|---|---|---|---|---|---|
| [Product user guide](product/user-guide.md) | Complete MVP user journey | Product users, support, QA | Product owner | 2026-08-03 | Major UI navigation changes, workflow contract changes, release-candidate preparation |
| [System overview](architecture/system-overview.md) | System context, boundaries, state, integrations, and reliability | Engineers, architects, reviewers | System architect | 2026-08-03 | Workflow, database, integration, privileged-operation, or topology changes |
| [Module boundaries](architecture/module-boundaries.md) | Modular-monolith ownership rules | Engineers | System architect | 2026-08-03 | Module or database ownership changes |
| [Operations guide](operations/operations-guide.md) | Runtime, deployment, observability, migration, and rollback operations | Operations, engineers | Operations owner | 2026-08-03 | Deployment topology or privileged-operation changes |
| [Backup and disaster recovery](operations/backup-restore-disaster-recovery.md) | Backup, restore, and rehearsal controls | Operations, security | Operations owner | 2026-08-03 | Storage, database, or recovery-process changes |
| [Security documentation](security/threat-model.md) | Threat model and security controls | Security, engineers | Security reviewer | 2026-08-03 | New integration capability, trust-boundary, or privileged-operation change |
| [Scenario catalog](testing/deterministic-scenario-catalog.md) | Deterministic release scenarios | QA, engineers | QA owner | 2026-08-03 | Workflow contract or simulation behavior change |
| [Runbooks](runbooks/README.md) | Operator recovery procedures | Operations, on-call responders | Operations owner | 2026-08-03 | Recovery command, integration, or deployment change |
| [Evidence](evidence/) | Reviewed acceptance and security evidence | Release reviewers | Release manager | 2026-08-03 | New candidate or evidence invalidation |
| [Releases](releases/) | Versioned candidate notes and rollback information | Release reviewers, operators | Release manager | 2026-08-03 | Release-candidate preparation |
| [Local demonstration](local-demo.md) | Deterministic simulation-only demonstration | Product, QA, evaluators | QA owner | 2026-08-03 | Demo seed or navigation change |

## Review cadence

Owners review their documents during release-candidate preparation and whenever
the listed trigger occurs. Review confirms links, commands, role boundaries,
authority statements, and simulation labels remain accurate.

Documentation review does not substitute for required human approvals or
candidate-specific evidence.
