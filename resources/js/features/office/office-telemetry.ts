import { useCallback, useEffect, useRef } from 'react';
import type { OfficeQualityPresetKey } from '@/features/office/quality-presets';
import type {
    OfficeRendererCapabilityReason,
    OfficeRendererCapabilityStatus,
    OfficeRendererFailureReason,
} from '@/features/office/webgl-capability';

export type OfficeTelemetryEventType =
    | 'capability_checked'
    | 'load_started'
    | 'load_succeeded'
    | 'load_failed'
    | 'quality_changed'
    | 'frame_window';

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

export type OfficeTelemetryEventInput = {
    type: OfficeTelemetryEventType;
    qualityPreset: OfficeQualityPresetKey;
    reducedMotion: boolean;
    capabilityStatus?: OfficeRendererCapabilityStatus;
    capabilityReason?: OfficeRendererCapabilityReason;
    failureReason?: OfficeRendererFailureReason;
    frame?: OfficeFrameWindow;
};

type OfficeTelemetryEnvelope = OfficeTelemetryEventInput & {
    sessionId: string;
    sequence: number;
    observedAt: string;
};

const MAX_BUFFERED_EVENTS = 50;
const FLUSH_BATCH_SIZE = 20;
const FLUSH_INTERVAL_MS = 15_000;

/**
 * Buffer bounded, privacy-safe renderer telemetry and send it to the
 * tenant-scoped Laravel endpoint.
 *
 * The buffer is memory-only. It never stores project names, agent IDs, ticket
 * IDs, URLs, user-agent strings, GPU renderer strings, or free-form content.
 */
export function useOfficeTelemetry(endpointUrl: string) {
    const queueRef = useRef<OfficeTelemetryEnvelope[]>([]);
    const sequenceRef = useRef(0);
    const flushingRef = useRef(false);
    const sessionIdRef = useRef(createSessionId());

    /**
     * Flush one bounded batch. Failed batches are returned to the head of the
     * queue and remain bounded.
     */
    const flush = useCallback(async () => {
        if (
            flushingRef.current ||
            queueRef.current.length === 0 ||
            typeof window === 'undefined'
        ) {
            return;
        }

        flushingRef.current = true;

        const batch = queueRef.current.splice(0, FLUSH_BATCH_SIZE);

        try {
            const xsrfToken = readXsrfToken();

            const response = await fetch(endpointUrl, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    ...(xsrfToken
                        ? {
                              'X-XSRF-TOKEN': xsrfToken,
                          }
                        : {}),
                },
                body: JSON.stringify({
                    events: batch,
                }),
            });

            if (!response.ok) {
                throw new Error(
                    `Office telemetry returned ${response.status}.`,
                );
            }
        } catch {
            queueRef.current = [...batch, ...queueRef.current].slice(
                0,
                MAX_BUFFERED_EVENTS,
            );
        } finally {
            flushingRef.current = false;
        }
    }, [endpointUrl]);

    /**
     * Add one allowlisted telemetry event to the memory-only buffer.
     */
    const record = useCallback(
        (event: OfficeTelemetryEventInput) => {
            sequenceRef.current += 1;

            queueRef.current.push({
                ...event,
                sessionId: sessionIdRef.current,
                sequence: sequenceRef.current,
                observedAt: new Date().toISOString(),
            });

            queueRef.current = queueRef.current.slice(-MAX_BUFFERED_EVENTS);

            if (queueRef.current.length >= FLUSH_BATCH_SIZE) {
                void flush();
            }
        },
        [flush],
    );

    /**
     * Periodically flush while the page is active and make one best-effort
     * keepalive request when the document becomes hidden.
     */
    useEffect(() => {
        const intervalId = window.setInterval(() => {
            void flush();
        }, FLUSH_INTERVAL_MS);

        function handleVisibilityChange() {
            if (document.visibilityState === 'hidden') {
                void flush();
            }
        }

        document.addEventListener('visibilitychange', handleVisibilityChange);

        return () => {
            window.clearInterval(intervalId);
            document.removeEventListener(
                'visibilitychange',
                handleVisibilityChange,
            );
            void flush();
        };
    }, [flush]);

    return {
        record,
        flush,
    };
}

/**
 * Create a session identifier without using account or device identity.
 */
function createSessionId() {
    if (
        typeof crypto !== 'undefined' &&
        typeof crypto.randomUUID === 'function'
    ) {
        return crypto.randomUUID();
    }

    return '00000000-0000-4000-8000-000000000000';
}

/**
 * Read Laravel's encrypted XSRF cookie for same-origin fetch requests.
 */
function readXsrfToken() {
    if (typeof document === 'undefined') {
        return null;
    }

    const cookie = document.cookie
        .split('; ')
        .find((value) => value.startsWith('XSRF-TOKEN='));

    if (!cookie) {
        return null;
    }

    return decodeURIComponent(cookie.slice('XSRF-TOKEN='.length));
}
