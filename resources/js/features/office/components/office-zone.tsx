import { Html } from '@react-three/drei';
import type { ThreeEvent } from '@react-three/fiber';
import type { OfficeZoneDefinition } from '@/features/office/office-zone-layout';
import type { OfficeRoom } from '@/features/office/types';

type Props = {
    room: OfficeRoom;
    definition: OfficeZoneDefinition;
    selected: boolean;
    onSelect: () => void;
};

/**
 * Render one low-poly office zone from authoritative room state.
 */
export function OfficeZone({ room, definition, selected, onSelect }: Props) {
    const color = selected ? '#e4e4e7' : roomStateColor(room.state);

    /**
     * Select the room without writing any workflow state.
     */
    function selectRoom(event: ThreeEvent<MouseEvent>) {
        event.stopPropagation();
        onSelect();
    }

    return (
        <group position={definition.position}>
            <mesh
                name={`office-zone-${room.key}`}
                position={[0, -0.02, 0]}
                onClick={selectRoom}
                onPointerOver={(event) => {
                    event.stopPropagation();
                    document.body.style.cursor = 'pointer';
                }}
                onPointerOut={() => {
                    document.body.style.cursor = '';
                }}
            >
                <boxGeometry args={definition.size} />
                <meshStandardMaterial
                    color={color}
                    emissive={selected ? '#52525b' : '#18181b'}
                    emissiveIntensity={selected ? 0.45 : 0.15}
                    roughness={0.82}
                />
            </mesh>

            <Html
                center
                position={[0, 0.72, 0]}
                distanceFactor={12}
                style={{
                    pointerEvents: 'none',
                }}
            >
                <div
                    aria-hidden="true"
                    className="min-w-32 rounded-md border bg-background/90 px-3 py-2 text-center text-xs shadow-sm backdrop-blur"
                >
                    <p className="font-medium">{definition.label}</p>
                    <p className="mt-1 text-muted-foreground">
                        {room.activeAgents} active · {room.actionableCount}{' '}
                        actionable
                    </p>
                </div>
            </Html>
        </group>
    );
}

/**
 * Map authoritative room state to a simple scene material color.
 */
function roomStateColor(state: string) {
    switch (state) {
        case 'working':
        case 'planning':
        case 'implementing':
        case 'reviewing':
        case 'validating':
            return '#2563eb';
        case 'waiting_for_human':
            return '#ca8a04';
        case 'blocked':
        case 'failed':
            return '#dc2626';
        case 'retrying':
            return '#ea580c';
        case 'completed':
            return '#16a34a';
        case 'queued':
            return '#0891b2';
        default:
            return '#3f3f46';
    }
}
