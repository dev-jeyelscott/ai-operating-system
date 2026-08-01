<?php

use Illuminate\Support\Str;

$appName = env('APP_NAME', 'laravel');

if (
    ! is_string($appName)
    || trim($appName) === ''
) {
    $appName = 'laravel';
}

$horizonPrefix = env('HORIZON_PREFIX');

if (
    ! is_string($horizonPrefix)
    || trim($horizonPrefix) === ''
) {
    $horizonPrefix = Str::slug(
        $appName,
        '_',
    ).'_horizon:';
}

$integrationsQueue = env(
    'INTEGRATIONS_QUEUE_NAME',
    'integrations',
);

if (
    ! is_string($integrationsQueue)
    || trim($integrationsQueue) === ''
) {
    $integrationsQueue = 'integrations';
}

$integrationsQueue = trim($integrationsQueue);

return [
    'name' => env('HORIZON_NAME'),

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env(
        'HORIZON_PATH',
        'horizon',
    ),

    /*
     * Horizon metadata uses the default Redis connection.
     */
    'use' => 'default',

    'prefix' => $horizonPrefix,

    'middleware' => [
        'web',
    ],

    /*
     * Emit queue wait events independently for normal and integration work.
     */
    'waits' => [
        'redis:default' => 60,
        'redis:'.$integrationsQueue => 30,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [],

    'silenced_tags' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 64,

    /*
     * Each queue receives an independent supervisor and capacity ceiling.
     */
    'defaults' => [
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => [
                'default',
            ],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],

        'supervisor-integrations' => [
            'connection' => 'redis',
            'queue' => [
                $integrationsQueue,
            ],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,

            /*
             * The job timeout is 60 seconds. Keep the supervisor timeout below
             * Redis retry_after, which is currently 90 seconds.
             */
            'timeout' => 75,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-default' => [
                'maxProcesses' => 10,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],

            /*
             * A Notion outage can consume at most two integration workers and
             * cannot starve the default workflow queue.
             */
            'supervisor-integrations' => [
                'maxProcesses' => 2,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 5,
            ],
        ],

        'local' => [
            'supervisor-default' => [
                'maxProcesses' => 3,
            ],

            'supervisor-integrations' => [
                'maxProcesses' => 1,
            ],
        ],

        'testing' => [
            'supervisor-default' => [
                'maxProcesses' => 1,
            ],

            'supervisor-integrations' => [
                'maxProcesses' => 1,
            ],
        ],
    ],

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
