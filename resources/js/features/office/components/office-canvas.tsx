import { Grid } from '@react-three/drei';
import { Canvas } from '@react-three/fiber';
import type { OfficeProjection } from '@/features/office/types';

type Props = {
    projection: OfficeProjection;
};

/**
 * Render the minimal React Three Fiber office shell.
 *
 * Rooms, navigation, agents, and animation are intentionally deferred to
 * AIOS-126 through AIOS-128.
 */
export default function OfficeCanvas({ projection }: Props) {
    return (
        <Canvas
            aria-label={`Interactive office for ${projection.project.name}`}
            camera={{
                position: [12, 10, 14],
                fov: 45,
                near: 0.1,
                far: 100,
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
            <directionalLight position={[8, 12, 6]} intensity={1.4} />

            <OfficeShellScene />
        </Canvas>
    );
}

/**
 * Render only lightweight primitives so the first 3D ticket introduces no
 * external asset, texture, font, or network dependency.
 */
function OfficeShellScene() {
    return (
        <group>
            <mesh position={[0, -0.2, 0]}>
                <boxGeometry args={[24, 0.35, 18]} />
                <meshStandardMaterial color="#18181b" />
            </mesh>

            <mesh position={[0, 0.45, 0]}>
                <boxGeometry args={[2.4, 0.9, 2.4]} />
                <meshStandardMaterial
                    color="#3f3f46"
                    emissive="#18181b"
                    emissiveIntensity={0.4}
                />
            </mesh>

            <Grid
                args={[24, 18]}
                position={[0, 0.01, 0]}
                cellColor="#3f3f46"
                cellSize={1}
                cellThickness={0.5}
                sectionColor="#71717a"
                sectionSize={4}
                sectionThickness={1}
                fadeDistance={28}
                fadeStrength={1}
            />
        </group>
    );
}
