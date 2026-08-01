<?php

declare(strict_types=1);

namespace App\Application\Operations;

/**
 * Evaluates privacy-safe office frame aggregates against preset budgets.
 */
final readonly class EvaluateOfficeRenderingBudget
{
    /**
     * Evaluate one validated renderer telemetry event.
     *
     * @param  array<string, mixed>  $event
     * @return array{
     *     evaluated: bool,
     *     exceeded: bool,
     *     preset: string|null,
     *     violations: list<array{
     *         metric: string,
     *         actual: float|int,
     *         expected: float|int,
     *         comparison: string
     *     }>
     * }
     */
    public function handle(array $event): array
    {
        if (
            ($event['type'] ?? null) !== 'frame_window'
            || ! is_array($event['frame'] ?? null)
        ) {
            return [
                'evaluated' => false,
                'exceeded' => false,
                'preset' => null,
                'violations' => [],
            ];
        }

        $preset = is_string($event['qualityPreset'] ?? null)
            ? $event['qualityPreset']
            : 'balanced';

        /** @var array<string, int|float> $budget */
        $budget = config(
            sprintf('performance.renderer.%s', $preset),
            config('performance.renderer.balanced', []),
        );

        /** @var array<string, mixed> $frame */
        $frame = $event['frame'];

        $violations = [];

        $this->addMinimumViolation(
            violations: $violations,
            metric: 'average_fps',
            actual: $frame['averageFps'] ?? null,
            expected: $budget['min_average_fps'] ?? null,
        );

        $this->addMaximumViolation(
            violations: $violations,
            metric: 'p95_frame_ms',
            actual: $frame['p95FrameMs'] ?? null,
            expected: $budget['max_p95_frame_ms'] ?? null,
        );

        $this->addMaximumViolation(
            violations: $violations,
            metric: 'draw_calls',
            actual: $frame['drawCalls'] ?? null,
            expected: $budget['max_draw_calls'] ?? null,
        );

        $this->addMaximumViolation(
            violations: $violations,
            metric: 'triangles',
            actual: $frame['triangles'] ?? null,
            expected: $budget['max_triangles'] ?? null,
        );

        $this->addMaximumViolation(
            violations: $violations,
            metric: 'textures',
            actual: $frame['textures'] ?? null,
            expected: $budget['max_textures'] ?? null,
        );

        return [
            'evaluated' => true,
            'exceeded' => $violations !== [],
            'preset' => $preset,
            'violations' => $violations,
        ];
    }

    /**
     * Add a violation when a metric is below its required minimum.
     *
     * @param  list<array{
     *     metric: string,
     *     actual: float|int,
     *     expected: float|int,
     *     comparison: string
     * }>  $violations
     */
    private function addMinimumViolation(
        array &$violations,
        string $metric,
        mixed $actual,
        mixed $expected,
    ): void {
        if (
            ! is_numeric($actual)
            || ! is_numeric($expected)
            || (float) $actual >= (float) $expected
        ) {
            return;
        }

        $violations[] = [
            'metric' => $metric,
            'actual' => (float) $actual,
            'expected' => (float) $expected,
            'comparison' => 'minimum',
        ];
    }

    /**
     * Add a violation when a metric exceeds its permitted maximum.
     *
     * @param  list<array{
     *     metric: string,
     *     actual: float|int,
     *     expected: float|int,
     *     comparison: string
     * }>  $violations
     */
    private function addMaximumViolation(
        array &$violations,
        string $metric,
        mixed $actual,
        mixed $expected,
    ): void {
        if (
            ! is_numeric($actual)
            || ! is_numeric($expected)
            || (float) $actual <= (float) $expected
        ) {
            return;
        }

        $violations[] = [
            'metric' => $metric,
            'actual' => (float) $actual,
            'expected' => (float) $expected,
            'comparison' => 'maximum',
        ];
    }
}
