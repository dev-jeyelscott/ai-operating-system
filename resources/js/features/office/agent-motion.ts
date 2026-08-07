import { MathUtils } from 'three';
import type { OfficeState } from '@/features/office/types';

/**
 * Decorative motion settings for one authoritative office state.
 *
 * These values control presentation only. They never alter workflow state.
 */
export type AgentMotionProfile = {
    movementDamping: number;
    bobAmplitude: number;
    bobFrequency: number;
    pulseAmplitude: number;
    pulseFrequency: number;
    turnAmplitude: number;
    turnFrequency: number;
};

const DEFAULT_PROFILE: AgentMotionProfile = {
    movementDamping: 9,
    bobAmplitude: 0,
    bobFrequency: 0,
    pulseAmplitude: 0,
    pulseFrequency: 0,
    turnAmplitude: 0,
    turnFrequency: 0,
};

/**
 * Return decorative animation settings for an authoritative office state.
 *
 * Unknown values intentionally degrade to a motionless profile instead of
 * inventing progress or activity.
 */
export function agentMotionProfile(
    state: OfficeState | string,
): AgentMotionProfile {
    switch (state) {
        case 'reading_documents':
            return profile({
                bobAmplitude: 0.025,
                bobFrequency: 1.2,
                turnAmplitude: 0.08,
                turnFrequency: 0.65,
            });
        case 'planning':
            return profile({
                bobAmplitude: 0.035,
                bobFrequency: 1.4,
                pulseAmplitude: 0.018,
                pulseFrequency: 1.1,
            });
        case 'selecting_ticket':
            return profile({
                bobAmplitude: 0.03,
                bobFrequency: 1.7,
                turnAmplitude: 0.18,
                turnFrequency: 0.9,
            });
        case 'implementing':
        case 'working':
            return profile({
                bobAmplitude: 0.025,
                bobFrequency: 2.2,
                pulseAmplitude: 0.015,
                pulseFrequency: 1.8,
            });
        case 'validating':
            return profile({
                bobAmplitude: 0.018,
                bobFrequency: 1.8,
                turnAmplitude: 0.1,
                turnFrequency: 0.7,
            });
        case 'creating_pull_request':
            return profile({
                bobAmplitude: 0.02,
                bobFrequency: 1.5,
                pulseAmplitude: 0.022,
                pulseFrequency: 1.4,
            });
        case 'reviewing':
            return profile({
                bobAmplitude: 0.02,
                bobFrequency: 1.3,
                turnAmplitude: 0.12,
                turnFrequency: 0.55,
            });
        case 'blocked':
            return profile({
                movementDamping: 14,
                pulseAmplitude: 0.04,
                pulseFrequency: 1.4,
                turnAmplitude: 0.04,
                turnFrequency: 0.7,
            });
        case 'retrying':
            return profile({
                movementDamping: 12,
                bobAmplitude: 0.03,
                bobFrequency: 1.9,
                pulseAmplitude: 0.05,
                pulseFrequency: 1.6,
            });
        case 'waiting_for_approval':
        case 'waiting_for_human':
            return profile({
                movementDamping: 11,
                bobAmplitude: 0.012,
                bobFrequency: 0.8,
                pulseAmplitude: 0.015,
                pulseFrequency: 0.7,
            });
        case 'completed':
            return profile({
                movementDamping: 14,
                bobAmplitude: 0.008,
                bobFrequency: 0.6,
            });
        case 'failed':
            return profile({
                movementDamping: 15,
                turnAmplitude: 0.025,
                turnFrequency: 0.45,
            });
        case 'idle':
        default:
            return profile({
                movementDamping: 10,
                bobAmplitude: 0.01,
                bobFrequency: 0.55,
            });
    }
}

/**
 * Smooth one numeric value using a frame-rate-independent exponential damp.
 */
export function dampAgentValue(
    current: number,
    target: number,
    damping: number,
    delta: number,
): number {
    const safeDelta = Math.min(Math.max(delta, 0), 0.1);

    return MathUtils.damp(current, target, Math.max(damping, 0), safeDelta);
}

/**
 * Resolve a deterministic phase offset from the stable agent identifier.
 *
 * This spreads identical animations without using randomness or local state.
 */
export function agentMotionPhase(agentId: string): number {
    let hash = 0;

    for (const character of agentId) {
        hash = (hash * 31 + character.charCodeAt(0)) >>> 0;
    }

    return (hash % 628) / 100;
}

/**
 * Merge state-specific values with the safe default profile.
 */
function profile(overrides: Partial<AgentMotionProfile>): AgentMotionProfile {
    return {
        ...DEFAULT_PROFILE,
        ...overrides,
    };
}
