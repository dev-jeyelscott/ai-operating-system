<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Reporting window
    |--------------------------------------------------------------------------
    |
    | Operational rates and durations are calculated from activity inside this
    | rolling window. Active executions, workflows, leases, and dead letters
    | remain visible even when they started before the window.
    |
    */
    'window_days' => (int) env(
        'OPERATIONAL_METRICS_WINDOW_DAYS',
        7,
    ),

    /*
    |--------------------------------------------------------------------------
    | Queue wait threshold
    |--------------------------------------------------------------------------
    |
    | This mirrors the default Horizon queue wait threshold. It identifies
    | queued workflow executions that have exceeded the expected start delay.
    |
    */
    'queue_long_wait_seconds' => (int) env(
        'OPERATIONAL_METRICS_QUEUE_LONG_WAIT_SECONDS',
        60,
    ),

    /*
    |--------------------------------------------------------------------------
    | Lease heartbeat threshold
    |--------------------------------------------------------------------------
    |
    | An active lease with a heartbeat older than this value is operationally
    | stale. Recovery policy still decides whether it may be released.
    |
    */
    'lease_stale_after_seconds' => (int) env(
        'OPERATIONAL_METRICS_LEASE_STALE_AFTER_SECONDS',
        120,
    ),
];
