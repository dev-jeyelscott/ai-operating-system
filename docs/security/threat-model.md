# AI Operating System Threat Model

- Version: 1.0
- Status: Initial approved baseline
- Review owner: Human operator
- Review cadence: Every material trust-boundary change

## 1. Protected assets

- User identity and authenticated sessions
- Project and organization data
- Approved source-of-truth documents
- Repository metadata and future repository credentials
- Notion credentials and synchronized task content
- Provider and runtime credentials
- Workflow and approval state
- Execution assumptions, artifacts, evidence, and costs
- Audit history
- Object-storage artifacts
- Database backups and recovery material

## 2. Actors

- Authorized human operator
- Authorized project member
- Unauthorized internet user
- Malicious or compromised member
- Compromised external integration
- Malicious uploaded document
- Malicious repository content
- Untrusted provider output
- Compromised future execution runtime

## 3. Trust boundaries

1. Browser to Laravel application
2. Laravel application to PostgreSQL
3. Laravel application to Redis and Horizon
4. Laravel application to S3 or MinIO
5. Laravel application to Notion
6. Laravel application to Reverb clients
7. Laravel application to future provider adapters
8. Future provider runtime to isolated repository workspace
9. CI runner to repository and dependency registries

## 4. Governing security rules

- Authorization is enforced by application policy, never by model output.
- Documents, tickets, repository files, logs, and provider responses are
  untrusted input.
- Secrets must not appear in logs, prompts, ordinary domain records, or browser
  payloads.
- Simulation cannot create verified implementation or test evidence.
- Consequential side effects must be authorized, idempotent, and auditable.
- Future real execution must run inside an isolated least-privilege workspace.
- Protected branches cannot be changed without explicit policy and approval.

## 5. Threat register

| ID | Threat | Impact | Initial controls | Implementation mapping |
|---|---|---:|---|---|
| THR-001 | Cross-project or tenant data access | Critical | Project-scoped policies, tenant-safe queries, authorization tests | AIOS-013, AIOS-017, AIOS-020 |
| THR-002 | Prompt injection in documents, tickets, repository files, or logs | High | Treat content as data, policy outside prompts, injection flags, redaction | AIOS-042, AIOS-043 |
| THR-003 | Malicious or oversized file upload | High | MIME and size validation, quarantine, malware abstraction, parser isolation | AIOS-034, AIOS-035, AIOS-036 |
| THR-004 | Duplicate external side effects from retries | High | Idempotency keys, outbox, deduplicated consumers, stable external keys | AIOS-050, AIOS-051, AIOS-057, AIOS-082 |
| THR-005 | Credential exposure in logs or provider context | Critical | Encryption, redaction, secret references, least privilege | AIOS-007, AIOS-027, AIOS-043 |
| THR-006 | Provider grants itself permissions through generated text | Critical | Capability policy and authorization enforced outside providers | AIOS-054, AIOS-066 |
| THR-007 | Unsafe future repository command execution | Critical | Isolated workspace, command allowlist, path restrictions, resource limits, approval | AIOS-095 and post-MVP execution hardening |
| THR-008 | Simulation displayed as verified evidence | High | Evidence classification, non-deception labels, verification gates | AIOS-058, AIOS-097, AIOS-113 |
| THR-009 | Audit history modification or deletion | High | Append-only application behavior and restricted persistence access | AIOS-018, AIOS-060 |
| THR-010 | Notion drift silently changes approved task scope | High | Versioned synchronization, reconciliation, human conflict decision | AIOS-085, AIOS-086 |
| THR-011 | Queue poisoning or forged workflow transition | High | Versioned event envelopes, state-machine guards, schema validation | AIOS-047, AIOS-049, AIOS-140 |
| THR-012 | Session theft or authentication abuse | High | Secure session handling, regeneration, throttling, step-up confirmation | AIOS-011, AIOS-019 |
| THR-013 | Dependency or container supply-chain compromise | High | Lockfiles, pinned images, audits, controlled updates | AIOS-005, AIOS-148 |
| THR-014 | Reverb event leaks project information | High | Authorized channels and project-scoped subscriptions | AIOS-061 and project authorization work |
| THR-015 | Stored artifact accessed without authorization | High | Private bucket, scoped application access, randomized identifiers | AIOS-027, AIOS-034, AIOS-058 |

## 6. Security assumptions

- PostgreSQL is the authoritative durable state store.
- Redis is operational and must not be the sole location of authoritative data.
- MinIO is used only for local development.
- Amazon S3 is used in production with private access by default.
- Real repository writes are prohibited during the MVP.
- The initial deployment is a modular monolith, not a distributed service mesh.

## 7. Review triggers

Review this threat model when:

- A new external integration is added
- A new document parser or upload type is introduced
- A real execution provider is enabled
- Repository write permissions are enabled
- Authentication or tenancy boundaries change
- A new sensitive-data category is stored
- A Critical or High security incident occurs
- A material architecture decision is replaced

## 8. Phase 0 security status

Phase 0 establishes security baselines and maps controls to later tickets. It
does not claim that later project isolation, upload, provider, or workflow
controls are already implemented.
