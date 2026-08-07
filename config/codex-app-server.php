<?php

declare(strict_types=1);

return [
    'executable' => env(
        'CODEX_APP_SERVER_EXECUTABLE',
        '/usr/local/bin/codex',
    ),

    'arguments' => [
        'app-server',
        '--listen',
        'stdio://',
    ],

    'version_arguments' => [
        '--version',
    ],

    'expected_binary_version' => env(
        'CODEX_APP_SERVER_BINARY_VERSION',
        '',
    ),

    'protocol_version' => env(
        'CODEX_APP_SERVER_PROTOCOL_VERSION',
        'stable',
    ),

    'schema_fingerprint' => env(
        'CODEX_APP_SERVER_SCHEMA_FINGERPRINT',
        '',
    ),

    'startup_timeout_seconds' => 30,

    'request_timeout_seconds' => 30,

    'shutdown_grace_seconds' => 10,

    'maximum_message_bytes' => 1_048_576,

    'maximum_events' => 100_000,

    'maximum_stderr_bytes' => 1_048_576,

    'maximum_json_depth' => 32,

    'allowed_environment' => [
        'LANG',
        'LC_ALL',
        'TZ',
        'SSL_CERT_FILE',
        'SSL_CERT_DIR',
        'HTTPS_PROXY',
        'NO_PROXY',
    ],

    'allowed_server_methods' => [
        'thread/started',
        'thread/status/changed',
        'thread/tokenUsage/updated',
        'thread/closed',

        'turn/started',
        'turn/completed',
        'turn/diff/updated',

        'item/started',
        'item/completed',
        'item/agentMessage/delta',
        'item/reasoning/textDelta',
        'item/reasoning/summaryTextDelta',
        'item/commandExecution/outputDelta',
        'item/fileChange/outputDelta',

        'item/commandExecution/requestApproval',
        'item/fileChange/requestApproval',
        'serverRequest/resolved',

        'warning',
        'configWarning',
    ],

    /*
    |--------------------------------------------------------------------------
    | Durable provider persistence
    |--------------------------------------------------------------------------
    */

    'persistence' => [
        'maximum_event_bytes' => 262_144,

        'heartbeat_interval_seconds' => 15,

        'stale_heartbeat_seconds' => 45,

        'transcript_chunk_bytes' => 1_048_576,

        'transcript_chunk_seconds' => 2,

        'maximum_transcript_bytes_per_attempt' => 26_214_400,

        'transcript_retention_days' => 30,

        'redaction_version' => 'v1',
    ],
];
