import { describe, expect, it } from 'vitest';
import { OfficeFrameWindowAccumulator } from '@/features/office/office-performance';

describe('OfficeFrameWindowAccumulator', () => {
    it('emits one healthy aggregate', () => {
        const accumulator = new OfficeFrameWindowAccumulator(1_000);
        let result = null;

        for (let frame = 0; frame < 60; frame += 1) {
            result = accumulator.push(1 / 60, {
                drawCalls: 20,
                triangles: 10_000,
                geometries: 12,
                textures: 4,
            });
        }

        expect(result).toMatchObject({
            frameCount: 60,
            drawCalls: 20,
            triangles: 10_000,
            geometries: 12,
            textures: 4,
            degraded: false,
        });

        expect(result?.averageFps).toBeCloseTo(60, 0);
    });

    it('marks sustained slow frames as degraded', () => {
        const accumulator = new OfficeFrameWindowAccumulator(1_000);
        let result = null;

        for (let frame = 0; frame < 20; frame += 1) {
            result = accumulator.push(0.05, {
                drawCalls: 30,
                triangles: 25_000,
                geometries: 18,
                textures: 6,
            });
        }

        expect(result).toMatchObject({
            degraded: true,
            p95FrameMs: 50,
        });

        expect(result?.averageFps).toBeCloseTo(20, 0);
    });
});
