#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_ROOT="${1:?The repository root argument is required.}"
PHP_BINARY="${HOME}/.config/herd-lite/bin/php"

if [[ ! -x "$PHP_BINARY" ]]; then
    printf 'ERROR: Herd Lite PHP was not found: %s\n' "$PHP_BINARY" >&2
    exit 127
fi

exec "$PHP_BINARY" "$PROJECT_ROOT/artisan" boost:mcp
