import { Html } from '@react-three/drei';
import type { ThreeEvent } from '@react-three/fiber';
import { officeStatePresentation } from '@/features/office/office-state-presentation';
import type { Vector3Tuple } from '@/features/office/office-zone-layout';
import type { OfficeAgent } from '@/features/office/types';

type Props = {
    agent: OfficeAgent;
    position: Vector3Tuple;
    focused: boolean;
    onSelect: () => void;
};

/**
 * Render one static logical-agent avatar from authoritative projection state.
 *
 * Animation is intentionally absent until AIOS-128.
 */
export function LogicalAgentAvatar({
    agent,
    position,
    focused,
    onSelect,
}: Props) {
    const presentation = officeStatePresentation(agent.officeState);

    /**
     * Focus the agent's authoritative room without changing workflow state.
     */
    function selectAgent(event: ThreeEvent<MouseEvent>) {
        event.stopPropagation();
        onSelect();
    }

    return (
        <group
            name={`logical-agent-${agent.id}`}
            position={position}
            scale={focused ? 1.08 : 1}
            onClick={selectAgent}
            onPointerOver={(event) => {
                event.stopPropagation();
                document.body.style.cursor = 'pointer';
            }}
            onPointerOut={() => {
                document.body.style.cursor = '';
            }}
        >
            <mesh position={[0, 0.75, 0]}>
                <capsuleGeometry args={[0.3, 0.65, 4, 8]} />
                <meshStandardMaterial
                    color={presentation.color}
                    emissive={presentation.emissive}
                    emissiveIntensity={presentation.emissiveIntensity}
                    roughness={0.62}
                />
            </mesh>

            <mesh position={[0, 1.45, 0]}>
                <sphereGeometry args={[0.28, 16, 16]} />
                <meshStandardMaterial color="#e4e4e7" roughness={0.72} />
            </mesh>

            <mesh position={[0, 0.08, 0]} rotation={[Math.PI / 2, 0, 0]}>
                <torusGeometry args={[0.48, 0.055, 8, 24]} />
                <meshStandardMaterial
                    color={presentation.ringColor}
                    emissive={presentation.emissive}
                    emissiveIntensity={0.65}
                />
            </mesh>

            <Html
                center
                position={[0, 2.02, 0]}
                distanceFactor={11}
                style={{
                    pointerEvents: 'none',
                }}
            >
                <div
                    aria-hidden="true"
                    className="min-w-40 rounded-md border bg-background/95 px-3 py-2 text-center text-xs shadow-md backdrop-blur"
                >
                    <div className="flex items-center justify-center gap-2">
                        <span className="font-medium">{agent.role}</span>
                        {agent.provider === 'simulation' && (
                            <span className="rounded bg-amber-500/15 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700 dark:text-amber-300">
                                SIM
                            </span>
                        )}
                    </div>
                    <p className="mt-1 text-muted-foreground">
                        {presentation.label}
                    </p>
                    {agent.ticketId && (
                        <p className="mt-1 font-mono text-[10px]">
                            {agent.ticketId}
                        </p>
                    )}
                </div>
            </Html>
        </group>
    );
}
