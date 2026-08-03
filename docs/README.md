# AI Operating System documentation

## Source-of-truth precedence

The approved product specification and baseline define product intent. Accepted
ADRs define durable architecture decisions. The implemented code, migrations,
tests, and operational scripts define the current executable contract. Where a
conflict is found, stop and resolve it through the project decision process.

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
and simulation labels remain accurate; it does not substitute for the required
human approvals in release evidence.
