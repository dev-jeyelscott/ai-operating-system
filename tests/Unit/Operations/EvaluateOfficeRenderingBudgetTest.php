<?php

declare(strict_types=1);

use App\Application\Operations\EvaluateOfficeRenderingBudget;
use Tests\TestCase;

/*
 * This test evaluates the application's configured renderer budgets, so it
 * must boot Laravel's configuration container while remaining database-free.
 */
uses(TestCase::class);

it('accepts a frame window inside the balanced budget', function (): void {
    $result = app(EvaluateOfficeRenderingBudget::class)->handle([
        'type' => 'frame_window',
        'qualityPreset' => 'balanced',
        'frame' => [
            'averageFps' => 55,
            'p95FrameMs' => 24,
            'drawCalls' => 180,
            'triangles' => 320000,
            'textures' => 70,
        ],
    ]);

    expect($result)
        ->evaluated->toBeTrue()
        ->exceeded->toBeFalse()
        ->violations->toBe([]);
});

it('reports each exceeded renderer budget', function (): void {
    $result = app(EvaluateOfficeRenderingBudget::class)->handle([
        'type' => 'frame_window',
        'qualityPreset' => 'balanced',
        'frame' => [
            'averageFps' => 18,
            'p95FrameMs' => 65,
            'drawCalls' => 300,
            'triangles' => 700000,
            'textures' => 120,
        ],
    ]);

    expect($result['evaluated'])->toBeTrue()
        ->and($result['exceeded'])->toBeTrue()
        ->and(array_column(
            $result['violations'],
            'metric',
        ))->toEqualCanonicalizing([
            'average_fps',
            'p95_frame_ms',
            'draw_calls',
            'triangles',
            'textures',
        ]);
});

it('does not evaluate non-frame telemetry events', function (): void {
    $result = app(EvaluateOfficeRenderingBudget::class)->handle([
        'type' => 'load_succeeded',
        'qualityPreset' => 'balanced',
    ]);

    expect($result)
        ->evaluated->toBeFalse()
        ->exceeded->toBeFalse();
});
