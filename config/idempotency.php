<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Coordination Cache Store
    |--------------------------------------------------------------------------
    |
    | Redis should be used by shared production workers. Tests may override
    | this with Laravel's in-memory array store.
    |
    */
    'cache_store' => env(
        'IDEMPOTENCY_CACHE_STORE',
        env('CACHE_STORE'),
    ),

    /*
    |--------------------------------------------------------------------------
    | Lock Wait
    |--------------------------------------------------------------------------
    |
    | Maximum time a duplicate caller waits for the current owner before it
    | receives a retryable command result.
    |
    */
    'lock_wait_seconds' => (int) env(
        'IDEMPOTENCY_LOCK_WAIT_SECONDS',
        5,
    ),

    /*
    |--------------------------------------------------------------------------
    | Processing Claim TTL
    |--------------------------------------------------------------------------
    |
    | A processing claim older than this may be reclaimed. Synchronous
    | application commands must complete inside this window.
    |
    */
    'processing_ttl_seconds' => (int) env(
        'IDEMPOTENCY_PROCESSING_TTL_SECONDS',
        900,
    ),

    /*
    |--------------------------------------------------------------------------
    | Completed Result Retention
    |--------------------------------------------------------------------------
    |
    | Terminal results remain replayable for this period. Expired records may
    | be reclaimed lazily when the same scope and key are submitted again.
    |
    */
    'retention_seconds' => (int) env(
        'IDEMPOTENCY_RETENTION_SECONDS',
        86400,
    ),
];
