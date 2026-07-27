<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default attempt timeout
    |--------------------------------------------------------------------------
    |
    | This value is snapshotted onto an execution when it is created. Changing
    | the environment later must not silently change an execution already in
    | progress.
    |
    */
    'timeout_seconds' => (int) env(
        'EXECUTION_TIMEOUT_SECONDS',
        900,
    ),

    /*
    |--------------------------------------------------------------------------
    | Retry backoff
    |--------------------------------------------------------------------------
    |
    | Retry delays grow exponentially until max_delay_seconds. Jitter is
    | deterministic and derived from the execution ID and attempt number.
    |
    */
    'retry' => [
        'base_delay_seconds' => (int) env(
            'EXECUTION_RETRY_BASE_DELAY_SECONDS',
            30,
        ),

        'max_delay_seconds' => (int) env(
            'EXECUTION_RETRY_MAX_DELAY_SECONDS',
            900,
        ),

        'jitter_percent' => (int) env(
            'EXECUTION_RETRY_JITTER_PERCENT',
            20,
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recovery scanner
    |--------------------------------------------------------------------------
    |
    | Keep each scheduler pass bounded so a large backlog does not monopolize
    | the scheduler process.
    |
    */
    'recovery_batch_size' => (int) env(
        'EXECUTION_RECOVERY_BATCH_SIZE',
        200,
    ),
];
