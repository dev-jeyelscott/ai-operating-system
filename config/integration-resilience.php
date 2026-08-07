<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Shared integration circuit breaker
    |--------------------------------------------------------------------------
    |
    | Circuit state is transient coordination data. Production and normal
    | development environments must therefore use the Redis cache store.
    |
    */

    'circuit' => [
        /*
         * Open the circuit after this number of consecutive failures for the
         * same provider credential and operation channel.
         */
        'failure_threshold' => (int) env(
            'INTEGRATION_CIRCUIT_FAILURE_THRESHOLD',
            5,
        ),

        /*
         * Discard stale closed-state failure counters after this window.
         */
        'failure_window_seconds' => (int) env(
            'INTEGRATION_CIRCUIT_FAILURE_WINDOW_SECONDS',
            60,
        ),

        /*
         * Reject provider calls for this duration after the circuit opens.
         */
        'open_seconds' => (int) env(
            'INTEGRATION_CIRCUIT_OPEN_SECONDS',
            60,
        ),

        /*
         * Allow one recovery probe during this lease. Other callers continue
         * to fail fast while the probe is active.
         */
        'half_open_lease_seconds' => (int) env(
            'INTEGRATION_CIRCUIT_HALF_OPEN_LEASE_SECONDS',
            15,
        ),

        /*
         * Serialize state transitions across horizontally scaled workers.
         */
        'lock_seconds' => (int) env(
            'INTEGRATION_CIRCUIT_LOCK_SECONDS',
            5,
        ),

        'lock_wait_seconds' => (int) env(
            'INTEGRATION_CIRCUIT_LOCK_WAIT_SECONDS',
            2,
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notion queue backpressure
    |--------------------------------------------------------------------------
    */

    'notion' => [
        'queue' => [
            'connection' => env(
                'INTEGRATIONS_QUEUE_CONNECTION',
                'redis',
            ),

            'name' => env(
                'INTEGRATIONS_QUEUE_NAME',
                'integrations',
            ),

            /*
             * Limits the number of queued roadmap publication jobs started by
             * one organization. This supplements, rather than replaces, the
             * provider's request-level 429 handling.
             */
            'jobs_per_minute' => (int) env(
                'NOTION_PUBLICATION_JOBS_PER_MINUTE',
                12,
            ),

            /*
             * Delay an overlapping roadmap job briefly instead of allowing
             * two jobs to publish or retry the same roadmap concurrently.
             */
            'overlap_release_seconds' => (int) env(
                'NOTION_OVERLAP_RELEASE_SECONDS',
                15,
            ),

            /*
             * Must remain greater than the job timeout so abandoned overlap
             * locks can be recovered safely.
             */
            'overlap_expire_seconds' => (int) env(
                'NOTION_OVERLAP_EXPIRE_SECONDS',
                90,
            ),
        ],

        /*
         * Only transient provider failures contribute to circuit opening.
         * Credential, permission, schema, and data conflicts require explicit
         * remediation and must not be hidden behind a temporary circuit.
         */
        'transient_failure_categories' => [
            'conflict',
            'rate_limited',
            'provider_unavailable',
            'provider_error',
            'malformed_response',
        ],
    ],
];
