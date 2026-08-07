<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | HTTP baseline samples
    |--------------------------------------------------------------------------
    |
    | Each backend performance test makes this many requests and evaluates the
    | p95 latency, maximum query count, and maximum response payload.
    |
    */
    'samples' => (int) env(
        'PERFORMANCE_BASELINE_SAMPLES',
        5,
    ),

    /*
    |--------------------------------------------------------------------------
    | Server-rendered and projection budgets
    |--------------------------------------------------------------------------
    */
    'server' => [
        'operations_dashboard' => [
            'max_queries' => 60,
            'max_p95_latency_ms' => 1500,
            'max_payload_bytes' => 600_000,
        ],

        'operational_metrics' => [
            'max_queries' => 25,
            'max_p95_latency_ms' => 1000,
            'max_payload_bytes' => 300_000,
        ],

        'office_projection' => [
            'max_queries' => 15,
            'max_p95_latency_ms' => 750,
            'max_payload_bytes' => 350_000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Browser navigation and resource budgets
    |--------------------------------------------------------------------------
    */
    'browser' => [
        'operations_max_navigation_ms' => 5000,
        'office_max_navigation_ms' => 6000,
        'office_max_encoded_resource_bytes' => 5_000_000,
    ],

    /*
    |--------------------------------------------------------------------------
    | 3D renderer budgets by quality preset
    |--------------------------------------------------------------------------
    |
    | These values evaluate the aggregate frame_window events already emitted
    | by the office renderer.
    |
    */
    'renderer' => [
        'low' => [
            'min_average_fps' => 24,
            'max_p95_frame_ms' => 50,
            'max_draw_calls' => 150,
            'max_triangles' => 250_000,
            'max_textures' => 48,
        ],

        'balanced' => [
            'min_average_fps' => 30,
            'max_p95_frame_ms' => 40,
            'max_draw_calls' => 250,
            'max_triangles' => 500_000,
            'max_textures' => 96,
        ],

        'high' => [
            'min_average_fps' => 30,
            'max_p95_frame_ms' => 40,
            'max_draw_calls' => 400,
            'max_triangles' => 1_000_000,
            'max_textures' => 160,
        ],
    ],
];
