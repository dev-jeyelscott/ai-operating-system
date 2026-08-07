import { useFrame } from '@react-three/fiber';
import { useRef } from 'react';
import { OfficeFrameWindowAccumulator } from '@/features/office/office-performance';
import type { OfficeFrameWindow } from '@/features/office/office-performance';

type Props = {
    onSample: (sample: OfficeFrameWindow) => void;
};

/**
 * Collect aggregated renderer health without causing React renders per frame.
 */
export function OfficePerformanceMonitor({ onSample }: Props) {
    const accumulatorRef = useRef(new OfficeFrameWindowAccumulator());

    useFrame((state, delta) => {
        if (document.visibilityState !== 'visible') {
            accumulatorRef.current.reset();

            return;
        }

        const sample = accumulatorRef.current.push(delta, {
            drawCalls: state.gl.info.render.calls,
            triangles: state.gl.info.render.triangles,
            geometries: state.gl.info.memory.geometries,
            textures: state.gl.info.memory.textures,
        });

        if (sample) {
            onSample(sample);
        }
    });

    return null;
}
