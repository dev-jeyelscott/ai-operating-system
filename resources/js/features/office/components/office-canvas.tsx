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
import { officeQualityPreset } from '@/features/office/quality-presets';
import type {
    OfficeQualityPreset,
    OfficeQualityPresetKey,
} from '@/features/office/quality-presets';
import type { OfficeProjection, OfficeRoomKey } from '@/features/office/types';

type Props = {
    projection: OfficeProjection;
    selectedRoom: OfficeRoomKey;
    reducedMotion: boolean;
    qualityPreset: OfficeQualityPresetKey;
    onSelectRoom: (room: OfficeRoomKey) => void;
};

type SceneProps = {
    projection: OfficeProjection;
    selectedRoom: OfficeRoomKey;
    reducedMotion: boolean;
    quality: OfficeQualityPreset;
    onSelectRoom: (room: OfficeRoomKey) => void;
};

/**
 * Render authoritative rooms and projected logical-agent avatars with the
 * selected presentation-only rendering configuration.
 */
export default function OfficeCanvas({
    projection,
    selectedRoom,
    reducedMotion,
    qualityPreset,
    onSelectRoom,
}: Props) {
    const quality = officeQualityPreset(qualityPreset);

    return (
        <Canvas
            key={quality.key}
            aria-label={`Interactive office for ${projection.project.name}`}
            camera={{
                position: [0, 8, 15],
                fov: 45,
                near: 0.1,
                far: 120,
            }}
            dpr={quality.dpr}
            frameloop={reducedMotion ? 'demand' : 'always'}
            gl={{
                antialias: quality.antialias,
                powerPreference: 'high-performance',
            }}
            shadows={quality.shadows}
        >
            <color attach="background" args={['#09090b']} />

            <ambientLight intensity={0.8} />
            <directionalLight
                position={[10, 14, 8]}
                intensity={1.5}
                castShadow={quality.shadows}
                shadow-mapSize={[quality.shadowMapSize, quality.shadowMapSize]}
            />

            <OfficeScene
                projection={projection}
                selectedRoom={selectedRoom}
                reducedMotion={reducedMotion}
                quality={quality}
                onSelectRoom={onSelectRoom}
            />

            <OfficeCamera
                selectedRoom={selectedRoom}
                reducedMotion={reducedMotion}
            />
        </Canvas>
    );
}

/**
 * Render every room and agent from projection state.
 */
function OfficeScene({
    projection,
    selectedRoom,
    reducedMotion,
    quality,
    onSelectRoom,
}: SceneProps) {
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
            <mesh position={[0, -0.25, 0]} receiveShadow={quality.shadows}>
                <boxGeometry args={[26, 0.35, 20]} />
                <meshStandardMaterial color="#18181b" />
            </mesh>

            {quality.showGrid && (
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
            )}

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
                        quality={quality}
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
                        reducedMotion={reducedMotion}
                        quality={quality}
                        onSelect={() => onSelectRoom(agent.room)}
                    />
                );
            })}
        </group>
    );
}

/**
 * Move the camera to the selected authoritative room.
 *
 * Reduced motion disables CameraControls interpolation and snaps immediately.
 */
function OfficeCamera({
    selectedRoom,
    reducedMotion,
}: {
    selectedRoom: OfficeRoomKey;
    reducedMotion: boolean;
}) {
    const controls = useRef<ElementRef<typeof CameraControls>>(null);

    useEffect(() => {
        const definition = officeZone(selectedRoom);

        void controls.current?.setLookAt(
            ...definition.cameraPosition,
            ...definition.cameraTarget,
            !reducedMotion,
        );
    }, [reducedMotion, selectedRoom]);

    return (
        <CameraControls
            ref={controls}
            makeDefault
            minDistance={4}
            maxDistance={28}
            minPolarAngle={0.35}
            maxPolarAngle={Math.PI / 2.15}
            truckSpeed={1.2}
            dollySpeed={0.8}
        />
    );
}
