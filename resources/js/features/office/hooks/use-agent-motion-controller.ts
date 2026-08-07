import { useFrame, useThree } from '@react-three/fiber';
import { useLayoutEffect, useMemo, useRef } from 'react';
import type { RefObject } from 'react';
import type { Group } from 'three';
import {
    agentMotionPhase,
    agentMotionProfile,
    dampAgentValue,
} from '@/features/office/agent-motion';
import type { OfficeState } from '@/features/office/types';

export type AgentPosition = [number, number, number];

type Options = {
    agentId: string;
    officeState: OfficeState | string;
    targetPosition: AgentPosition;
    reducedMotion: boolean;
    rootRef: RefObject<Group | null>;
    visualRef: RefObject<Group | null>;
};

/**
 * Animate one avatar toward its projected destination and decorate its current
 * authoritative state without writing workflow truth.
 */
export function useAgentMotionController({
    agentId,
    officeState,
    targetPosition,
    reducedMotion,
    rootRef,
    visualRef,
}: Options): void {
    const invalidate = useThree((state) => state.invalidate);

    const profile = useMemo(
        () => agentMotionProfile(officeState),
        [officeState],
    );

    const phase = useMemo(() => agentMotionPhase(agentId), [agentId]);

    const initializedRef = useRef(false);

    useLayoutEffect(() => {
        const root = rootRef.current;
        const visual = visualRef.current;

        if (!root || !visual) {
            return;
        }

        if (!initializedRef.current || reducedMotion) {
            root.position.set(...targetPosition);
            visual.position.y = 0;
            visual.rotation.y = 0;
            visual.scale.setScalar(1);
            initializedRef.current = true;
            invalidate();
        }
    }, [invalidate, reducedMotion, rootRef, targetPosition, visualRef]);

    useFrame((frameState, delta) => {
        const root = rootRef.current;
        const visual = visualRef.current;

        if (!root || !visual) {
            return;
        }

        if (reducedMotion) {
            root.position.set(...targetPosition);
            visual.position.y = 0;
            visual.rotation.y = 0;
            visual.scale.setScalar(1);

            return;
        }

        root.position.x = dampAgentValue(
            root.position.x,
            targetPosition[0],
            profile.movementDamping,
            delta,
        );
        root.position.y = dampAgentValue(
            root.position.y,
            targetPosition[1],
            profile.movementDamping,
            delta,
        );
        root.position.z = dampAgentValue(
            root.position.z,
            targetPosition[2],
            profile.movementDamping,
            delta,
        );

        const elapsed = frameState.clock.elapsedTime + phase;

        visual.position.y =
            Math.sin(elapsed * profile.bobFrequency * Math.PI * 2) *
            profile.bobAmplitude;

        visual.rotation.y =
            Math.sin(elapsed * profile.turnFrequency * Math.PI * 2) *
            profile.turnAmplitude;

        const pulse =
            1 +
            Math.sin(elapsed * profile.pulseFrequency * Math.PI * 2) *
                profile.pulseAmplitude;

        visual.scale.setScalar(pulse);
    });
}
