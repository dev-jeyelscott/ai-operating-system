import { CameraControls, Grid } from '@react-three/drei';
import { Canvas, useThree } from '@react-three/fiber';
import { useEffect, useMemo, useRef } from 'react';
import type { ElementRef } from 'react';
import { buildAgentPositions } from '@/features/office/agent-layout';
import { LogicalAgentAvatar } from '@/features/office/components/logical-agent-avatar';
import { OfficePerformanceMonitor } from '@/features/office/components/office-performance-monitor';
import { OfficeZone } from '@/features/office/components/office-zone';
import type { OfficeFrameWindow } from '@/features/office/office-performance';
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
import type { OfficeRendererFailureReason } from '@/features/office/webgl-capability';

type Props = {
    projection: OfficeProjection;
    selectedRoom: OfficeRoomKey;
    selectedAgentId: string | null;
    reducedMotion: boolean;
    qualityPreset: OfficeQualityPresetKey;
    onSelectRoom: (room: OfficeRoomKey) => void;
    onSelectAgent: (agentId: string) => void;
    onRendererFailure: (reason: OfficeRendererFailureReason) => void;
    onRendererReady: () => void;
    onPerformanceSample: (sample: OfficeFrameWindow) => void;
};

type SceneProps = {
    projection: OfficeProjection;
    selectedRoom: OfficeRoomKey;
    selectedAgentId: string | null;
    reducedMotion: boolean;
    quality: OfficeQualityPreset;
    onSelectRoom: (room: OfficeRoomKey) => void;
    onSelectAgent: (agentId: string) => void;
};

/**
 * Render authoritative rooms and logical-agent avatars.
 */
export default function OfficeCanvas({
    projection,
    selectedRoom,
    selectedAgentId,
    reducedMotion,
    qualityPreset,
    onSelectRoom,
    onSelectAgent,
    onRendererFailure,
    onRendererReady,
    onPerformanceSample,
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
                failIfMajorPerformanceCaveat:
                    quality.failIfMajorPerformanceCaveat,
                powerPreference: quality.powerPreference,
            }}
            shadows={quality.shadows}
            onCreated={onRendererReady}
        >
            <OfficeRendererContextMonitor
                onRendererFailure={onRendererFailure}
            />

            <OfficePerformanceMonitor onSample={onPerformanceSample} />

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
                selectedAgentId={selectedAgentId}
                reducedMotion={reducedMotion}
                quality={quality}
                onSelectRoom={onSelectRoom}
                onSelectAgent={onSelectAgent}
            />

            <OfficeCamera
                selectedRoom={selectedRoom}
                reducedMotion={reducedMotion}
            />
        </Canvas>
    );
}

/**
 * Monitor the renderer-owned canvas for context loss.
 */
function OfficeRendererContextMonitor({
    onRendererFailure,
}: {
    onRendererFailure: (reason: OfficeRendererFailureReason) => void;
}) {
    const canvas = useThree((state) => state.gl.domElement);

    useEffect(() => {
        /**
         * Preserve authoritative projection state and replace only WebGL.
         */
        function handleContextLost(event: Event) {
            event.preventDefault();
            onRendererFailure('context_lost');
        }

        canvas.addEventListener('webglcontextlost', handleContextLost);

        return () => {
            canvas.removeEventListener('webglcontextlost', handleContextLost);
        };
    }, [canvas, onRendererFailure]);

    return null;
}

/**
 * Render every room and agent from projection state.
 */
function OfficeScene({
    projection,
    selectedRoom,
    selectedAgentId,
    reducedMotion,
    quality,
    onSelectRoom,
    onSelectAgent,
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
                        roomFocused={selectedRoom === agent.room}
                        selected={selectedAgentId === agent.id}
                        reducedMotion={reducedMotion}
                        quality={quality}
                        onSelect={() => onSelectAgent(agent.id)}
                    />
                );
            })}
        </group>
    );
}

/**
 * Move the camera to the selected authoritative room.
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
