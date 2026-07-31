import { CameraControls, Grid } from '@react-three/drei';
import { Canvas } from '@react-three/fiber';
import { useEffect, useMemo, useRef } from 'react';
import type { ElementRef } from 'react';
import { buildAgentPositions } from '@/features/office/agent-layout';
import { LogicalAgentAvatar } from '@/features/office/components/logical-agent-avatar';
import { OfficeZone } from '@/features/office/components/office-zone';
import {
    OFFICE_ZONE_ORDER,
    officeZone,
} from '@/features/office/office-zone-layout';
import type { OfficeProjection, OfficeRoomKey } from '@/features/office/types';

type Props = {
    projection: OfficeProjection;
    selectedRoom: OfficeRoomKey;
    onSelectRoom: (room: OfficeRoomKey) => void;
};

/**
 * Render authoritative rooms and projected logical-agent avatars.
 */
export default function OfficeCanvas({
    projection,
    selectedRoom,
    onSelectRoom,
}: Props) {
    return (
        <Canvas
            aria-label={`Interactive office for ${projection.project.name}`}
            camera={{
                position: [0, 8, 15],
                fov: 45,
                near: 0.1,
                far: 120,
            }}
            dpr={[1, 1.5]}
            frameloop="demand"
            gl={{
                antialias: true,
                powerPreference: 'high-performance',
            }}
        >
            <color attach="background" args={['#09090b']} />

            <ambientLight intensity={0.8} />
            <directionalLight position={[10, 14, 8]} intensity={1.5} />

            <OfficeScene
                projection={projection}
                selectedRoom={selectedRoom}
                onSelectRoom={onSelectRoom}
            />

            <OfficeCamera selectedRoom={selectedRoom} />
        </Canvas>
    );
}

/**
 * Render every room and agent from projection state.
 */
function OfficeScene({ projection, selectedRoom, onSelectRoom }: Props) {
    const roomsByKey = useMemo(
        () => new Map(projection.rooms.map((room) => [room.key, room])),
        [projection.rooms],
    );

    const agentPositions = useMemo(
        () => buildAgentPositions(projection.agents),
        [projection.agents],
    );

    return (
        <group>
            <mesh position={[0, -0.25, 0]}>
                <boxGeometry args={[26, 0.35, 20]} />
                <meshStandardMaterial color="#18181b" />
            </mesh>

            <Grid
                args={[26, 20]}
                position={[0, 0.01, 0]}
                cellColor="#3f3f46"
                cellSize={1}
                cellThickness={0.5}
                sectionColor="#71717a"
                sectionSize={4}
                sectionThickness={1}
                fadeDistance={32}
                fadeStrength={1}
            />

            {OFFICE_ZONE_ORDER.map((roomKey) => {
                const room = roomsByKey.get(roomKey);

                if (!room) {
                    return null;
                }

                return (
                    <OfficeZone
                        key={roomKey}
                        room={room}
                        definition={officeZone(roomKey)}
                        selected={selectedRoom === roomKey}
                        onSelect={() => onSelectRoom(roomKey)}
                    />
                );
            })}

            {projection.agents.map((agent) => {
                const position = agentPositions[agent.id];

                if (!position) {
                    return null;
                }

                return (
                    <LogicalAgentAvatar
                        key={agent.id}
                        agent={agent}
                        position={position}
                        focused={selectedRoom === agent.room}
                        onSelect={() => onSelectRoom(agent.room)}
                    />
                );
            })}
        </group>
    );
}

/**
 * Move the camera immediately to the selected room.
 */
function OfficeCamera({ selectedRoom }: { selectedRoom: OfficeRoomKey }) {
    const controls = useRef<ElementRef<typeof CameraControls>>(null);

    useEffect(() => {
        const definition = officeZone(selectedRoom);

        void controls.current?.setLookAt(
            ...definition.cameraPosition,
            ...definition.cameraTarget,
            false,
        );
    }, [selectedRoom]);

    return (
        <CameraControls
            ref={controls}
            makeDefault
            minDistance={4}
            maxDistance={24}
            maxPolarAngle={Math.PI / 2.08}
            dollyToCursor={false}
        />
    );
}
