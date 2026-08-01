import { Html } from '@react-three/drei';
import type { ThreeEvent } from '@react-three/fiber';
import { useRef } from 'react';
import type { Group } from 'three';
import { useAgentMotionController } from '@/features/office/hooks/use-agent-motion-controller';
import type { AgentPosition } from '@/features/office/hooks/use-agent-motion-controller';
import { officeStatePresentation } from '@/features/office/office-state-presentation';
import type { OfficeAgent } from '@/features/office/types';

type Props = {
    agent: OfficeAgent;
    position: AgentPosition;
    focused: boolean;
    reducedMotion: boolean;
    onSelect: () => void;
};

/**
 * Render one low-poly logical agent from authoritative projection data.
 */
export function LogicalAgentAvatar({
    agent,
    position,
    focused,
    reducedMotion,
    onSelect,
}: Props) {
    const rootRef = useRef<Group | null>(null);
    const visualRef = useRef<Group | null>(null);
    const presentation = officeStatePresentation(agent.officeState);

    useAgentMotionController({
        agentId: agent.id,
        officeState: agent.officeState,
        targetPosition: position,
        reducedMotion,
        rootRef,
        visualRef,
    });

    /**
     * Focus the authoritative room associated with this agent.
     */
    function handleSelect(event: ThreeEvent<MouseEvent>) {
        event.stopPropagation();
        onSelect();
    }

    return (
        <group ref={rootRef} name={`agent-${agent.id}`}>
            <group ref={visualRef} onClick={handleSelect}>
                <mesh position={[0, 0.52, 0]} castShadow>
                    <capsuleGeometry args={[0.16, 0.42, 4, 8]} />
                    <meshStandardMaterial
                        color={presentation.color}
                        emissive={presentation.emissive}
                        emissiveIntensity={presentation.emissiveIntensity}
                        roughness={0.65}
                    />
                </mesh>

                <mesh position={[0, 0.98, 0]} castShadow>
                    <sphereGeometry args={[0.19, 16, 12]} />
                    <meshStandardMaterial
                        color={presentation.color}
                        emissive={presentation.emissive}
                        emissiveIntensity={presentation.emissiveIntensity * 0.7}
                        roughness={0.7}
                    />
                </mesh>

                <mesh position={[0, 0.02, 0]} rotation={[-Math.PI / 2, 0, 0]}>
                    <ringGeometry args={[0.28, 0.38, 24]} />
                    <meshBasicMaterial
                        color={presentation.ringColor}
                        transparent
                        opacity={focused ? 1 : 0.7}
                    />
                </mesh>

                {focused && (
                    <mesh
                        position={[0, 0.02, 0]}
                        rotation={[-Math.PI / 2, 0, 0]}
                    >
                        <ringGeometry args={[0.43, 0.48, 24]} />
                        <meshBasicMaterial color="#f8fafc" />
                    </mesh>
                )}

                <Html
                    position={[0, 1.35, 0]}
                    center
                    distanceFactor={10}
                    style={{ pointerEvents: 'none' }}
                >
                    <div className="min-w-max rounded-md border border-white/10 bg-zinc-950/90 px-2 py-1 text-center text-[10px] text-white shadow-lg">
                        <p className="font-medium">{agent.role}</p>
                        <p className="text-zinc-300">{presentation.label}</p>
                        {agent.provider === 'simulation' && (
                            <p className="font-semibold text-amber-300">
                                Simulated · Unverified
                            </p>
                        )}
                    </div>
                </Html>
            </group>
        </group>
    );
}
