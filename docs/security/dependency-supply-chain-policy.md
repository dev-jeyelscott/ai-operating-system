# Dependency and Supply-Chain Policy

**Ticket:** AIOS-148  
**Owner:** AI Operating System maintainers  
**Effective date:** 2026-08-03  
**Review cadence:** Quarterly and after every material supply-chain incident  
**Integration branch:** `develop`

## 1. Purpose

This policy defines how PHP, JavaScript, GitHub Actions, and container
dependencies are selected, locked, reviewed, updated, audited, and rolled back.

The objective is reproducible installation, early vulnerability detection,
narrow privilege, traceable exceptions, and safe recovery without unnecessary
dependency-management infrastructure.

## 2. Scope

This policy covers:

- `composer.json`
- `composer.lock`
- `package.json`
- `pnpm-lock.yaml`
- `pnpm-workspace.yaml`
- `.github/dependabot.yml`
- `.github/workflows/*.yml`
- `compose.yaml`
- Tracked Dockerfiles
- Bootstrap and maintenance scripts that pull external images or binaries

## 3. Required lockfiles

The following lockfiles are mandatory and must remain committed:

- `composer.lock`
- `pnpm-lock.yaml`

Lockfiles must never be hand-edited.

A dependency manifest and its corresponding lockfile must be reviewed and
committed together whenever dependency resolution changes.

CI must use:

```bash
composer install --no-interaction --prefer-dist --no-progress
pnpm install --frozen-lockfile
```

A missing, stale, or inconsistent lockfile is a blocking finding.

## 4. Package-manager versions

The repository declares the supported pnpm version in `package.json`.

Developers and CI must use the declared package manager version. Package-manager
major upgrades require an individual pull request and complete quality
validation.

Composer runs through the project container or the CI PHP setup and must remain
compatible with PHP 8.5 and Laravel 13.

## 5. Dependency source restrictions

Production and development dependencies should resolve from their normal
package registries.

Transitive Git, tarball, and other exotic dependency sources are blocked
through:

```yaml
blockExoticSubdeps: true
```

New install-time build scripts are denied unless the package is explicitly
reviewed and added to `allowBuilds` in `pnpm-workspace.yaml`.

The review must verify:

1. Why the build script is required.
2. Which files or native artifacts it creates.
3. Whether it performs network access.
4. Whether the package is required in production.
5. Whether a safer package or prebuilt artifact exists.

## 6. Newly published package delay

pnpm dependency resolution uses a minimum release age of 1,440 minutes.

This reduces exposure to newly published compromised or accidentally broken
versions while preserving a short response window for routine updates.

A security update that must bypass the delay requires explicit maintainer
review and documented evidence.

## 7. Required CI checks

The canonical supply-chain command is:

```bash
composer supply-chain:check
```

It must perform all of the following:

1. Strict Composer manifest and lockfile validation.
2. Composer advisory scanning against locked versions.
3. Failure on abandoned Composer packages.
4. Production pnpm audit without exceptions.
5. Development pnpm audit with only explicitly approved exceptions.
6. Registry package-signature verification.

The complete repository quality command is:

```bash
composer ci:check
```

A dependency pull request cannot be approved while either command fails.

## 8. Vulnerability policy

### Critical and high severity

Critical and high-severity findings are blocking.

The preferred resolution order is:

1. Upgrade to a patched compatible release.
2. Replace the affected dependency.
3. Remove the dependency.
4. Apply an upstream-supported mitigation while completing the upgrade.
5. Approve a temporary exception only when exploitability is demonstrably limited and compensating controls are documented.

### Moderate severity

Moderate findings must be reviewed during the next dependency maintenance
cycle. They become blocking when they affect authentication, authorization,
secrets, uploaded content, external integrations, workflow integrity,
repository execution, or production availability.

### Low severity

Low findings are tracked and resolved during normal maintenance unless their
specific use in this application raises the effective risk.

## 9. Dependency exceptions

Exceptions must be:

- Limited to one advisory.
- Limited to the smallest possible dependency scope.
- Prohibited for production audits unless explicitly approved.
- Assigned an owner.
- Given an expiry date.
- Accompanied by compensating controls.
- Removed immediately when a patched compatible dependency is available.

An exception may not suppress unrelated advisories.

Current temporary exception

| Field               | Value                                                      |
| ------------------- | ---------------------------------------------------------- |
| Advisory            | `GHSA-mh99-v99m-4gvg`                                      |
| Scope               | Development dependencies only                              |
| Affected area       | Current ESLint-related transitive development tooling      |
| Production exposure | None accepted; production audit has no exception           |
| Owner               | AI Operating System maintainers                            |
| Expiry              | 2026-09-03                                                 |
| Enforcement         | `bin/check-frontend-dependency-audit`                      |
| Removal condition   | Compatible patched transitive dependency becomes available |


The audit script must fail after the expiry date unless the unmodified
development audit already passes.

## 10. Automated update policy

Dependabot checks these ecosystems weekly:

- Composer
- npm/pnpm
- GitHub Actions

Automated update pull requests target develop.

Patch and minor updates may be grouped by production or development scope.

Major updates remain individual pull requests and require:

- Release-note review
- Migration-guide review
- Compatibility review
- Rollback plan
- Full automated quality checks
- Manual verification where behavior changes

Dependabot pull requests are proposals only. They do not bypass review,
branch protection, CI, or merge authorization.

## 11. GitHub Actions policy

Third-party GitHub Actions must use a complete immutable commit SHA.

A readable version comment should remain next to the SHA.

Example:

```yaml
# v7
uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1
```

Tag-only, branch-only, major-only, and floating Action references are rejected.

## 12. Container image policy

Externally pulled container images must use:

repository:explicit-version@sha256:immutable-digest

The selected image must be reviewed for:

- Official publisher
- Supported architecture
- Expected operating system base
- Security advisories
- Release notes
- Digest correctness

Image updates must pass `bin/check-container-images` and complete application
quality checks.

13. Dependency update procedure

Create a dedicated branch:

```bash
git switch develop
git pull --ff-only origin develop
git switch -c chore/dependency-name-version
```

Update PHP dependencies through Composer:

```bash
./vendor/bin/sail composer update vendor/package --with-all-dependencies
```

Update frontend dependencies through pnpm:

```bash
./vendor/bin/sail pnpm update package-name
```

Never manually modify a lockfile.

Review:

```bash
git diff -- composer.json composer.lock
git diff -- package.json pnpm-lock.yaml pnpm-workspace.yaml
```

Run:

```bash
./vendor/bin/sail composer supply-chain:check
./vendor/bin/sail composer ci:check
./vendor/bin/sail pnpm test:e2e
```

The pull request must identify:

-  Dependency and version change
-  Reason for the update
-  Security advisories addressed
-  Release notes reviewed
-  Compatibility impact
-  Validation performed
-  Rollback procedure

## 14. Rollback

Rollback restores the previous reviewed manifests and lockfiles together.

For PHP:

```bash
git restore --source=<known-good-commit> -- composer.json composer.lock
./vendor/bin/sail composer install --no-interaction --prefer-dist --no-progress
```

For frontend dependencies:

```bash
git restore --source=<known-good-commit> -- \
    package.json \
    pnpm-lock.yaml \
    pnpm-workspace.yaml

./vendor/bin/sail pnpm install --frozen-lockfile
```

After restoration, run:

```bash
./vendor/bin/sail composer supply-chain:check
./vendor/bin/sail composer ci:check
./vendor/bin/sail pnpm test:e2e
```

Do not repair a broken deployment by deleting or regenerating a lockfile without
review.

## 15. Evidence requirements

Every dependency or supply-chain change must preserve:

- Changed manifests and lockfiles
- Advisory scan result
- Signature verification result
- Static analysis result
- Backend test result
- Frontend unit test result
- Type-check result
- Production build result
- Playwright result when behavior or browser dependencies are affected
- Relevant release notes
- Rollback instructions

Evidence must reflect commands that actually ran. Planned, simulated, or
unexecuted checks must not be represented as passing evidence.

## 16. Review checklist

Before approval, confirm:

- [ ] Manifest and lockfile changes are intentional.
- [ ] No unexpected transitive package source was introduced.
- [ ] No unreviewed install-time build script was introduced.
- [ ] Production audit has no exception.
- [ ] Every development audit exception is scoped and unexpired.
- [ ] Composer reports no blocking advisory or abandoned package.
- [ ] Package signatures pass verification.
- [ ] GitHub Actions remain pinned to full commit SHAs.
- [ ] Container images remain pinned to immutable digests.
- [ ] Full CI passes.
- [ ] Rollback restores a known-good lockfile state.
