import type { Dpr } from '@react-three/fiber';
import { describe, expect, it } from 'vitest';
import {
    DEFAULT_OFFICE_QUALITY_PRESET,
    OFFICE_QUALITY_PRESETS,
    isOfficeQualityPresetKey,
    officeQualityPreset,
} from '@/features/office/quality-presets';

/**
 * Return the maximum device-pixel ratio represented by one Canvas DPR value.
 */
function maximumDpr(dpr: Dpr): number {
    return Array.isArray(dpr) ? dpr[1] : dpr;
}

describe('officeQualityPreset', () => {
    it('uses balanced as the safe default', () => {
        expect(DEFAULT_OFFICE_QUALITY_PRESET).toBe('balanced');

        expect(officeQualityPreset('unsupported')).toEqual(
            OFFICE_QUALITY_PRESETS.balanced,
        );
    });

    it('recognizes only supported preset keys', () => {
        expect(isOfficeQualityPresetKey('low')).toBe(true);
        expect(isOfficeQualityPresetKey('balanced')).toBe(true);
        expect(isOfficeQualityPresetKey('high')).toBe(true);
        expect(isOfficeQualityPresetKey('automatic')).toBe(false);
    });

    it('progressively increases pixel-density cost', () => {
        expect(maximumDpr(OFFICE_QUALITY_PRESETS.low.dpr)).toBeLessThan(
            maximumDpr(OFFICE_QUALITY_PRESETS.balanced.dpr),
        );

        expect(maximumDpr(OFFICE_QUALITY_PRESETS.balanced.dpr)).toBeLessThan(
            maximumDpr(OFFICE_QUALITY_PRESETS.high.dpr),
        );
    });

    it('disables optional rendering work in low quality', () => {
        expect(OFFICE_QUALITY_PRESETS.low.antialias).toBe(false);
        expect(OFFICE_QUALITY_PRESETS.low.shadows).toBe(false);
        expect(OFFICE_QUALITY_PRESETS.low.showGrid).toBe(false);
        expect(OFFICE_QUALITY_PRESETS.low.roomLabels).toBe('selected');
        expect(OFFICE_QUALITY_PRESETS.low.agentLabels).toBe('focused');
    });

    it('uses the relaxed GPU context policy in low quality', () => {
        expect(OFFICE_QUALITY_PRESETS.low.powerPreference).toBe('low-power');

        expect(OFFICE_QUALITY_PRESETS.low.failIfMajorPerformanceCaveat).toBe(
            false,
        );
    });

    it('retains the current office defaults in balanced quality', () => {
        expect(OFFICE_QUALITY_PRESETS.balanced.dpr).toEqual([1, 1.5]);
        expect(OFFICE_QUALITY_PRESETS.balanced.antialias).toBe(true);
        expect(OFFICE_QUALITY_PRESETS.balanced.shadows).toBe(true);
        expect(OFFICE_QUALITY_PRESETS.balanced.showGrid).toBe(true);
        expect(OFFICE_QUALITY_PRESETS.balanced.powerPreference).toBe(
            'high-performance',
        );

        expect(
            OFFICE_QUALITY_PRESETS.balanced.failIfMajorPerformanceCaveat,
        ).toBe(true);

        expect(
            OFFICE_QUALITY_PRESETS.balanced.geometry.capsuleCapSegments,
        ).toBe(4);

        expect(
            OFFICE_QUALITY_PRESETS.balanced.geometry.capsuleRadialSegments,
        ).toBe(8);

        expect(
            OFFICE_QUALITY_PRESETS.balanced.geometry.sphereWidthSegments,
        ).toBe(16);

        expect(
            OFFICE_QUALITY_PRESETS.balanced.geometry.sphereHeightSegments,
        ).toBe(12);

        expect(OFFICE_QUALITY_PRESETS.balanced.geometry.ringThetaSegments).toBe(
            24,
        );
    });

    it('progressively increases procedural geometry detail', () => {
        const low = OFFICE_QUALITY_PRESETS.low.geometry.sphereWidthSegments;
        const balanced =
            OFFICE_QUALITY_PRESETS.balanced.geometry.sphereWidthSegments;
        const high = OFFICE_QUALITY_PRESETS.high.geometry.sphereWidthSegments;

        expect(low).toBeLessThan(balanced);
        expect(balanced).toBeLessThan(high);
    });

    it('progressively increases shadow-map resolution', () => {
        expect(OFFICE_QUALITY_PRESETS.low.shadowMapSize).toBeLessThan(
            OFFICE_QUALITY_PRESETS.balanced.shadowMapSize,
        );

        expect(OFFICE_QUALITY_PRESETS.balanced.shadowMapSize).toBeLessThan(
            OFFICE_QUALITY_PRESETS.high.shadowMapSize,
        );
    });
});
