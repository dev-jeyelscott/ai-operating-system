<?php

declare(strict_types=1);

use App\Application\Development\Consumers\DispatchDevelopmentExecution;
use App\Application\Development\Consumers\RedispatchDevelopmentRetry;
use App\Application\Operations\Consumers\RefreshOfficeProjection;
use App\Application\Planning\Consumers\DispatchPlanningExecution;
use App\Application\QualityAssurance\Consumers\DispatchQualityAssuranceExecution;
use App\Application\QualityAssurance\Consumers\RedispatchQualityAssuranceRetry;
use App\Application\Tickets\Consumers\ReleaseLeaseForTerminalExecution;

return [
    /*
    |--------------------------------------------------------------------------
    | Queue transport
    |--------------------------------------------------------------------------
    */

    'queue' => [
        'connection' => env(
            'OUTBOX_QUEUE_CONNECTION',
            'redis',
        ),

        /*
         * Keep the MVP on the existing default Horizon queue. A dedicated
         * supervisor should be introduced only when operational metrics show
         * that outbox traffic requires independent capacity.
         */
        'name' => env(
            'OUTBOX_QUEUE_NAME',
            'default',
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dispatcher behavior
    |--------------------------------------------------------------------------
    */

    'dispatcher' => [
        'batch_size' => (int) env(
            'OUTBOX_DISPATCH_BATCH_SIZE',
            100,
        ),

        'lease_seconds' => (int) env(
            'OUTBOX_DISPATCH_LEASE_SECONDS',
            60,
        ),

        'maximum_attempts' => (int) env(
            'OUTBOX_DISPATCH_MAX_ATTEMPTS',
            10,
        ),

        'base_backoff_seconds' => (int) env(
            'OUTBOX_DISPATCH_BASE_BACKOFF_SECONDS',
            5,
        ),

        'maximum_backoff_seconds' => (int) env(
            'OUTBOX_DISPATCH_MAX_BACKOFF_SECONDS',
            300,
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Registered consumers
    |--------------------------------------------------------------------------
    */

    'consumers' => [
        DispatchPlanningExecution::class,
        DispatchDevelopmentExecution::class,
        RedispatchDevelopmentRetry::class,
        DispatchQualityAssuranceExecution::class,
        RedispatchQualityAssuranceRetry::class,
        ReleaseLeaseForTerminalExecution::class,
        RefreshOfficeProjection::class,
    ],
];
