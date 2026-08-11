# AI Operating System MVP Definition of Done

## Review metadata

- Candidate commit: Not established; select the post-remediation `develop` SHA.
- Approval authority: AIOS-294 in the canonical Notion delivery tracker
- Candidate evidence: manual `release-candidate` GitHub Actions run for the exact SHA
- Reviewed at: Not performed
- Product owner: No current approval
- System architect: No current approval
- QA owner: No current approval
- Security reviewer: No current approval
- Operations owner: No current approval
- Release manager: No current approval
- Decision: Blocked

## Release decision rules

- PASS requires current passing evidence for every mandatory criterion.
- FAIL applies when a mandatory criterion fails.
- BLOCKED applies when evidence or a required human approval is missing.
- Conditional approval is not permitted for Critical or High findings.

## Evidence matrix

Every item below is blocked until its exact automated or manual evidence is
recorded against the selected candidate commit, timestamped, owned, and
independently reviewed where required.

| ID | Criterion | Result | Evidence | Owner | Reviewer |
|---|---|---|---|---|---|
| MVP-01 | Project creation and configuration | Blocked | Candidate test run required | Product owner | QA owner |
| MVP-02 | Configuration and integration validation | Blocked | Candidate test run required | Product owner | QA owner |
| MVP-03 | Document lifecycle and snapshot | Blocked | Candidate test run required | Product owner | QA owner |
| MVP-04 | Idempotent Start Project | Blocked | Candidate test run required | Engineering owner | QA owner |
| MVP-05 | Layer 1 roadmap and readiness | Blocked | Candidate test run required | Engineering owner | QA owner |
| MVP-06 | Human roadmap approval and rejection | Blocked | Manual candidate review required | Product owner | QA owner |
| MVP-07 | Duplicate-safe Notion publication | Blocked | Candidate acceptance run required | Integration owner | QA owner |
| MVP-08 | Ticket selection and claim prevention | Blocked | Concurrency suite required | Engineering owner | QA owner |
| MVP-09 | Simulated Layer 2 artifacts target develop | Blocked | Candidate acceptance run required | Engineering owner | QA owner |
| MVP-10 | Independent Layer 3 QA report | Blocked | Candidate acceptance run required | QA owner | System architect |
| MVP-11 | Human merge-decision actions | Blocked | Manual candidate review required | Product owner | QA owner |
| MVP-12 | Event-driven 3D office | Blocked | Browser suite required | Engineering owner | QA owner |
| MVP-13 | Accessible dashboard equivalence | Blocked | Accessibility suite required | Engineering owner | QA owner |
| MVP-14 | Simulation labels cannot imply verification | Blocked | Candidate acceptance run required | Product owner | Security reviewer |
| MVP-15 | Complete audit reconstruction | Blocked | Candidate test run required | Engineering owner | QA owner |
| MVP-16 | Retry and replay duplicate safety | Blocked | AIOS-155 acceptance required | Engineering owner | QA owner |
| MVP-17 | Mandatory scenarios pass | Blocked | Scenario catalog and tests required | QA owner | System architect |
| MVP-18 | Security and isolation sign-off | Blocked | Fresh named security review required | Security reviewer | Product owner |
| MVP-19 | Provider replacement contract compatibility | Blocked | Candidate contract tests required | Engineering owner | System architect |

## Mandatory scenario evidence

Each row requires the catalog seed, scenario fingerprint, actual result, and
candidate evidence location before it can pass.

| Scenario | Seed | Result | Evidence |
|---|---:|---|---|
| Happy path | Not run | Blocked | Candidate scenario and browser evidence required |
| Missing required document | Not run | Blocked | Candidate scenario evidence required |
| Conflicting documents | Not run | Blocked | Candidate scenario evidence required |
| Notion transient failure | Not run | Blocked | Candidate scenario evidence required |
| No workable ticket | Not run | Blocked | Candidate scenario evidence required |
| Dependency blocked | Not run | Blocked | Candidate scenario evidence required |
| Development validation failure | Not run | Blocked | Candidate scenario evidence required |
| Provider timeout | Not run | Blocked | Candidate scenario evidence required |
| Wrong PR target | Not run | Blocked | Candidate scenario evidence required |
| QA changes requested | Not run | Blocked | Candidate scenario evidence required |
| Merge ready, low risk | Not run | Blocked | Candidate scenario evidence required |
| Merge ready, high risk | Not run | Blocked | Candidate scenario evidence required |
| Duplicate Start Project | Not run | Blocked | Candidate scenario evidence required |
| Duplicate Notion publication retry | Not run | Blocked | Candidate scenario evidence required |

## Pre-candidate validation

These local results are informative only. They are not evidence for a release
candidate until they are repeated for the selected committed SHA and recorded
with the required owner and independent reviewer.

| Executed at | Check | Result | Notes |
|---|---|---|---|
| 2026-08-03 | Isolated full Laravel suite | Passed | 1,275 passed, 1 skipped, 6,276 assertions, 135.44 seconds, exit 0. |
| 2026-08-03 | Isolated PostgreSQL concurrency suite | Passed | 10 passed, 87 assertions, 8.97 seconds, no skipped tests or warnings. |
| 2026-08-03 | Security sign-off validator | Failed as intended | Manifest decision is `invalidated`; it references commit `94c0694d776db1a3660154bccd1b7f6404746eed` and has no human approval records. |

## Residual risks

- Candidate validation is incomplete: focused PostgreSQL-backed feature tests pass, but the full browser acceptance flow still requires a clean CI run after its server environment propagation fix.
- Candidate supply-chain evidence has not yet been produced by the release-candidate workflow.
- A clean isolated full Laravel suite passed on 2026-08-03: 1,275 passed, 1 skipped, and 6,276 assertions in 135.44 seconds (exit 0). This is preliminary local candidate evidence only; retain a clean CI run for the selected commit.
- Security review evidence is invalidated and requires named human approvals.
- No non-author user-guide validation or runbook tabletop exercise is recorded.
- `develop` has not been reconciled with `main` for final promotion.

## Final sign-offs and decision

Blocked awaiting independent reviewers. Do not create a promotion PR, tag, or GitHub prerelease until every
matrix item has current passing evidence and the required independent human
approvals.
