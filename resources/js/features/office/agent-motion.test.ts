import { describe, expect, it } from 'vitest';

import {
    agentMotionPhase,
    agentMotionProfile,
    dampAgentValue,
} from '@/features/office/agent-motion';

describe('agentMotionProfile', () => {
    it('returns active presentation for implementing agents', () => {
        const profile = agentMotionProfile('implementing');

        expect(profile.bobAmplitude).toBeGreaterThan(0);
        expect(profile.bobFrequency).toBeGreaterThan(0);
        expect(profile.pulseAmplitude).toBeGreaterThan(0);
    });

    it('returns an attention profile for blocked agents', () => {
        const profile = agentMotionProfile('blocked');

        expect(profile.pulseAmplitude).toBeGreaterThan(0);
        expect(profile.movementDamping).toBeGreaterThan(0);
    });

    it('degrades unknown states to a safe subtle profile', () => {
        const profile = agentMotionProfile('future_state');

        expect(profile.pulseAmplitude).toBe(0);
        expect(profile.turnAmplitude).toBe(0);
        expect(profile.bobAmplitude).toBeLessThanOrEqual(0.01);
    });
});

describe('dampAgentValue', () => {
    it('moves toward the target without overshooting', () => {
        const next = dampAgentValue(0, 10, 9, 1 / 60);

        expect(next).toBeGreaterThan(0);
        expect(next).toBeLessThan(10);
    });

    it('clamps extreme frame deltas', () => {
        const normal = dampAgentValue(0, 10, 9, 0.1);
        const restoredTab = dampAgentValue(0, 10, 9, 8);

        expect(restoredTab).toBe(normal);
    });
});

describe('agentMotionPhase', () => {
    it('is deterministic for the stable agent id', () => {
        expect(agentMotionPhase('execution-01')).toBe(
            agentMotionPhase('execution-01'),
        );
    });

    it('normally separates different agent ids', () => {
        expect(agentMotionPhase('execution-01')).not.toBe(
            agentMotionPhase('execution-02'),
        );
    });
});
