# Phase 0 Completion Evidence

## Repository

- Target branch: `develop`
- Clean-clone operating system: Windows 11 with Ubuntu/WSL2
- Runtime: PHP 8.5
- Node.js: 24
- Package manager: pnpm 11.4.0

## Required evidence

- [x] AIOS-001 application boots from clean clone
- [x] AIOS-002 module-boundary test passes
- [x] AIOS-003 all seven ADRs are accepted
- [x] AIOS-004 PostgreSQL, Redis, MinIO, Mailpit, Horizon, Reverb, scheduler, and Vite run locally
- [x] AIOS-005 GitHub Actions quality workflow passes
- [x] AIOS-006 engineering conventions are linked from CONTRIBUTING
- [x] AIOS-007 `app:check` passes locally and production validation fails unsafe configuration
- [x] AIOS-008 `/up`, `/health`, and `/ready` pass
- [x] AIOS-008 JSON logs contain request IDs and redact sentinel secrets
- [x] AIOS-009 stable error-contract tests pass
- [x] AIOS-010 threats and abuse cases map to implementation tickets

## Validation commands

```bash
./bin/bootstrap
./vendor/bin/sail composer ci:check
./vendor/bin/sail pnpm test:e2e
curl -fsS http://localhost/up
curl -fsS http://localhost/health
curl -fsS http://localhost/ready
```

Pull requests

Add the six merged pull-request links here.

Remaining risks

Document any accepted non-blocking risk here. Phase 1 must not start while a
Phase 0 exit criterion remains incomplete.
