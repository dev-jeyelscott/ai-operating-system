# Contributing

1. Read the approved project documentation and applicable ADRs.
2. Work directly from `develop` unless the delivery workflow explicitly says otherwise.
3. Use `feature/`, `fix/`, `refactor/`, `docs/`, or `chore/` prefixes when a separate branch is required.
4. Keep each pull request scoped to one coherent outcome.
5. Add or update automated tests.
6. Run the complete local quality gate.
7. Open normal pull requests against `develop`.
8. Do not merge when required checks fail or risks are unresolved.

## Codex and Laravel Boost

The tracked `.codex/config.toml` must remain portable. It launches Laravel
Boost through `bin/codex-laravel-boost` and must not contain personal home
directories, checkout locations, usernames, or unconditional WSL commands.

The launcher uses `php` from the active environment by default.

When a workstation requires a custom PHP executable, create the ignored local
override:

```bash
touch .codex/laravel-boost.local.sh
chmod +x .codex/laravel-boost.local.sh
```

Example local override:

```bash
#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_ROOT="${1:?The repository root argument is required.}"

exec "${HOME}/.config/herd-lite/bin/php" \
    "${PROJECT_ROOT}/artisan" \
    boost:mcp
```

Confirm that the file is ignored:


```bash
git check-ignore -v .codex/laravel-boost.local.sh
```

Never commit machine-specific paths or enable repository-wide network access
through shared agent configuration.

See `docs/engineering/conventions.md` and `docs/architecture/module-boundaries.md`.
