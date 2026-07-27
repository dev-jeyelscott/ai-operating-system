<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Real-time event-stream driver
    |--------------------------------------------------------------------------
    |
    | Application projection publishers depend only on RealTimeEventStream.
    | The broadcast driver currently uses Laravel Reverb through the configured
    | broadcasting connection. The null driver disables external delivery.
    |
    */

    'default' => env(
        'EVENT_STREAM_DRIVER',
        'broadcast',
    ),

    'broadcast' => [
        'connection' => env(
            'EVENT_STREAM_BROADCAST_CONNECTION',
            env('BROADCAST_CONNECTION', 'reverb'),
        ),
    ],
];
