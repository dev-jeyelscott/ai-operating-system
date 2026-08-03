#!/usr/bin/env bash

set -Eeuo pipefail

readonly PROJECT_ROOT="$(
    cd "$(dirname "${BASH_SOURCE[0]}")/../.." >/dev/null 2>&1
    pwd
)"

# Print an operational message without exposing secrets.
log() {
    printf '[operations] %s\n' "$*" >&2
}

# Stop execution with one actionable error message.
fail() {
    printf '[operations] ERROR: %s\n' "$*" >&2
    exit 1
}

# Require one executable before an operation starts.
require_command() {
    local command_name="$1"

    command -v "$command_name" >/dev/null 2>&1 || {
        fail "Required command is unavailable: ${command_name}"
    }
}

# Require one non-empty environment variable.
require_env() {
    local variable_name="$1"

    [[ -n "${!variable_name:-}" ]] || {
        fail "Required environment variable is missing: ${variable_name}"
    }
}

# Return a filesystem-safe UTC operation identifier.
utc_operation_id() {
    date -u '+%Y%m%dT%H%M%SZ'
}

# Restrict restore targets to simple PostgreSQL database identifiers.
assert_safe_database_name() {
    local database_name="$1"

    [[ "$database_name" =~ ^[a-zA-Z_][a-zA-Z0-9_]*$ ]] || {
        fail "Unsafe PostgreSQL database name: ${database_name}"
    }

    case "$database_name" in
        postgres | template0 | template1)
            fail "System PostgreSQL database cannot be used as a restore target: ${database_name}"
            ;;
    esac
}

# Calculate a portable SHA-256 digest for one file.
sha256_file() {
    local path="$1"

    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$path" | awk '{ print $1 }'

        return
    fi

    if command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "$path" | awk '{ print $1 }'

        return
    fi

    fail 'Neither sha256sum nor shasum is available.'
}

# Read one value from a key=value operations manifest.
manifest_value() {
    local key="$1"
    local manifest_path="$2"

    awk -F= -v expected_key="$key" '
        $1 == expected_key {
            sub(/^[^=]*=/, "")
            print
            exit
        }
    ' "$manifest_path"
}

# Validate the configured PostgreSQL tool execution mode.
validate_pg_tool_mode() {
    case "${PG_TOOLS_MODE:-native}" in
        native)
            return
            ;;
        docker-compose)
            require_command docker
            ;;
        docker)
            require_command docker
            require_env PG_DOCKER_CONTAINER
            ;;
        *)
            fail "Unsupported PG_TOOLS_MODE: ${PG_TOOLS_MODE}"
            ;;
    esac
}

# Execute one PostgreSQL client command natively or inside a container.
run_pg_tool() {
    local tool="$1"

    shift

    case "${PG_TOOLS_MODE:-native}" in
        native)
            PGPASSWORD="$PGPASSWORD" "$tool" "$@"
            ;;
        docker-compose)
            docker compose \
                --project-directory "$PROJECT_ROOT" \
                exec \
                -T \
                -e PGPASSWORD="$PGPASSWORD" \
                "${PG_DOCKER_SERVICE:-pgsql}" \
                "$tool" \
                "$@"
            ;;
        docker)
            docker exec \
                -i \
                -e PGPASSWORD="$PGPASSWORD" \
                "$PG_DOCKER_CONTAINER" \
                "$tool" \
                "$@"
            ;;
        *)
            fail "Unsupported PG_TOOLS_MODE: ${PG_TOOLS_MODE}"
            ;;
    esac
}

# Execute AWS CLI commands against AWS S3 or an S3-compatible endpoint.
aws_cli() {
    local -a global_arguments=()

    if [[ -n "${AWS_ENDPOINT_URL:-}" ]]; then
        global_arguments+=(--endpoint-url "$AWS_ENDPOINT_URL")
    fi

    aws "${global_arguments[@]}" "$@"
}
