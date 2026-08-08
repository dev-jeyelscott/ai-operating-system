<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Authentication Limits
    |--------------------------------------------------------------------------
    |
    | Authentication limits are intentionally conservative because these
    | endpoints are unauthenticated and are common credential-stuffing targets.
    |
    */

    'authentication' => [
        'login_per_minute' => (int) env(
            'RATE_LIMIT_LOGIN_PER_MINUTE',
            5,
        ),

        'two_factor_per_minute' => (int) env(
            'RATE_LIMIT_TWO_FACTOR_PER_MINUTE',
            5,
        ),

        'passkeys_per_minute' => (int) env(
            'RATE_LIMIT_PASSKEYS_PER_MINUTE',
            10,
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Privileged Project Command Limits
    |--------------------------------------------------------------------------
    |
    | Project command limits are scoped by actor, organization, and command.
    | The short window absorbs accidental double-clicks and burst abuse, while
    | the hourly window limits sustained automated command execution.
    |
    | Every command resolved by RateLimitServiceProvider must have its limits
    | defined inside this project_commands namespace.
    |
    */

    'project_commands' => [
        'store' => [
            'per_minute' => (int) env(
                'RATE_LIMIT_PROJECT_STORE_PER_MINUTE',
                10,
            ),
            'per_hour' => (int) env(
                'RATE_LIMIT_PROJECT_STORE_PER_HOUR',
                100,
            ),
        ],

        'update' => [
            'per_minute' => (int) env(
                'RATE_LIMIT_PROJECT_UPDATE_PER_MINUTE',
                20,
            ),
            'per_hour' => (int) env(
                'RATE_LIMIT_PROJECT_UPDATE_PER_HOUR',
                200,
            ),
        ],

        'upload' => [
            'per_minute' => (int) env(
                'RATE_LIMIT_DOCUMENT_UPLOAD_PER_MINUTE',
                10,
            ),
            'per_hour' => (int) env(
                'RATE_LIMIT_DOCUMENT_UPLOAD_PER_HOUR',
                100,
            ),
        ],

        'archive' => [
            'per_minute' => (int) env(
                'RATE_LIMIT_PROJECT_ARCHIVE_PER_MINUTE',
                10,
            ),
            'per_hour' => (int) env(
                'RATE_LIMIT_PROJECT_ARCHIVE_PER_HOUR',
                50,
            ),
        ],

        'restore' => [
            'per_minute' => (int) env(
                'RATE_LIMIT_PROJECT_RESTORE_PER_MINUTE',
                10,
            ),
            'per_hour' => (int) env(
                'RATE_LIMIT_PROJECT_RESTORE_PER_HOUR',
                50,
            ),
        ],

        /*
         * Credential writes are privileged project commands and must use the
         * same namespace consumed by the project-commands named limiter.
         */
        'credentials' => [
            'per_minute' => (int) env(
                'RATE_LIMIT_PROJECT_CREDENTIALS_PER_MINUTE',
                5,
            ),
            'per_hour' => (int) env(
                'RATE_LIMIT_PROJECT_CREDENTIALS_PER_HOUR',
                20,
            ),
        ],

        /*
         * Notion and Codex connection tests currently share the integration
         * test command bucket. Retain the existing environment variable names
         * to avoid changing deployed configuration as part of this bug fix.
         */
        'integration_test' => [
            'per_minute' => (int) env(
                'RATE_LIMIT_NOTION_TEST_PER_MINUTE',
                5,
            ),
            'per_hour' => (int) env(
                'RATE_LIMIT_NOTION_TEST_PER_HOUR',
                20,
            ),
        ],
    ],
];
