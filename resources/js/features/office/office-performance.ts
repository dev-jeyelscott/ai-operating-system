export type OfficeRendererInfo = {
    drawCalls: number;
    triangles: number;
    geometries: number;
    textures: number;
};

export type OfficeFrameWindow = {
    averageFps: number;
    p95FrameMs: number;
    maxFrameMs: number;
    sampleDurationMs: number;
    frameCount: number;
    drawCalls: number;
    triangles: number;
    geometries: number;
    textures: number;
    degraded: boolean;
};

const DEFAULT_WINDOW_MS = 5_000;
const WINDOW_COMPLETION_TOLERANCE_MS = 0.001;
const DEGRADED_AVERAGE_FPS = 30;
const DEGRADED_P95_FRAME_MS = 50;

/**
 * Aggregate render-loop deltas into bounded windows.
 *
 * This avoids React state writes and network requests inside `useFrame`.
 */
export class OfficeFrameWindowAccumulator {
    private elapsedMs = 0;
    private frameTimes: number[] = [];
    private latestRendererInfo: OfficeRendererInfo = {
        drawCalls: 0,
        triangles: 0,
        geometries: 0,
        textures: 0,
    };

    public constructor(private readonly windowMs = DEFAULT_WINDOW_MS) {}

    /**
     * Add one visible frame and return a completed sample when the window ends.
     *
     * A sub-microsecond tolerance prevents IEEE 754 accumulation errors from
     * delaying an otherwise complete frame window by one additional frame.
     */
    public push(
        deltaSeconds: number,
        rendererInfo: OfficeRendererInfo,
    ): OfficeFrameWindow | null {
        const frameMs = Math.max(0, deltaSeconds * 1_000);

        this.elapsedMs += frameMs;
        this.frameTimes.push(frameMs);
        this.latestRendererInfo = rendererInfo;

        if (
            this.elapsedMs + WINDOW_COMPLETION_TOLERANCE_MS <
            this.windowMs
        ) {
            return null;
        }

        const frameCount = this.frameTimes.length;
        const averageFps =
            frameCount === 0 ? 0 : frameCount / (this.elapsedMs / 1_000);

        const sortedFrameTimes = [...this.frameTimes].sort(
            (left, right) => left - right,
        );

        const p95Index = Math.min(
            sortedFrameTimes.length - 1,
            Math.max(0, Math.ceil(sortedFrameTimes.length * 0.95) - 1),
        );

        const p95FrameMs =
            sortedFrameTimes.length === 0 ? 0 : sortedFrameTimes[p95Index];

        const maxFrameMs =
            sortedFrameTimes.length === 0
                ? 0
                : sortedFrameTimes[sortedFrameTimes.length - 1];

        const sample = {
            averageFps: round(averageFps),
            p95FrameMs: round(p95FrameMs),
            maxFrameMs: round(maxFrameMs),
            sampleDurationMs: Math.round(this.elapsedMs),
            frameCount,
            ...this.latestRendererInfo,
            degraded:
                averageFps < DEGRADED_AVERAGE_FPS ||
                p95FrameMs > DEGRADED_P95_FRAME_MS,
        } satisfies OfficeFrameWindow;

        this.reset();

        return sample;
    }

    /**
     * Discard the current partial window.
     */
    public reset() {
        this.elapsedMs = 0;
        this.frameTimes = [];
        this.latestRendererInfo = {
            drawCalls: 0,
            triangles: 0,
            geometries: 0,
            textures: 0,
        };
    }
}

/**
 * Round telemetry values to two decimal places.
 */
function round(value: number) {
    return Math.round(value * 100) / 100;
}
