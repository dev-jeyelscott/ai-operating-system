# Deterministic Scenario Catalog

AIOS-151 defines one stable catalog for the 14 release-blocking MVP
scenarios.

The catalog command resolves a scenario slug, records the deterministic seed,
exposes provider-specific mappings, and produces a canonical fingerprint. The
release entry point `bin/run-release-scenarios` executes an explicit test or
browser seam for every catalog entry and records its command, exit result,
candidate SHA, fingerprint, and artifact checksums.

## Inspect the catalog

```bash
php artisan simulation:scenarios
php artisan simulation:scenarios --seed=42 --json
php artisan simulation:scenarios wrong_pr_target --seed=151 --json
```

Candidate execution is performed by the release workflow, or locally against
an exact checked-out SHA:

```bash
bin/run-release-scenarios "$(git rev-parse HEAD)" 155
```

## Release-blocking scenarios

| Scenario                             | Test seam                     |
| ------------------------------------ | ----------------------------- |
| `happy_path`                         | Complete workflow             |
| `missing_required_document`          | Start preflight and planning  |
| `conflicting_documents`              | Planning conflict handling    |
| `notion_transient_failure`           | Notion publication retry      |
| `no_workable_ticket`                 | Ticket selector idle state    |
| `dependency_blocked`                 | Ticket dependency eligibility |
| `development_validation_failure`     | Layer 2 validation and retry  |
| `provider_timeout`                   | Execution resilience          |
| `wrong_pr_target`                    | Repository target policy      |
| `qa_changes_requested`               | Layer 3 changes loop          |
| `merge_ready_low_risk`               | Low-risk merge advisory       |
| `merge_ready_high_risk`              | High-risk human escalation    |
| `duplicate_start_project`            | StartProject idempotency      |
| `duplicate_notion_publication_retry` | Notion upsert idempotency     |

## Seeded demo tenant

Run the demo seeder only in local or testing:

```bash
php artisan db:seed --class='Database\\Seeders\\DeterministicDemoSeeder'
```

Credentials:

- Email: `demo-owner@example.test`
- Password: `Demo-Password-2026`
- Organization: `AI Operating System Demonstration`

The seeder creates four projects covering happy-path,
conflicting-document, transient-Notion-failure, and high-risk-merge
demonstrations.

Every document is labeled `simulation_only` and
`actual_state_unverified`.

No external credential, verified evidence, repository write, or real
Notion side effect is created.
