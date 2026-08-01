import { act, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useOfficeTelemetry } from '@/features/office/office-telemetry';

describe('useOfficeTelemetry', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('sends only the bounded allowlisted event payload', async () => {
        const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(null, {
                status: 202,
            }),
        );

        const { result } = renderHook(() =>
            useOfficeTelemetry('/office-telemetry'),
        );

        act(() => {
            result.current.record({
                type: 'load_succeeded',
                qualityPreset: 'balanced',
                reducedMotion: false,
                capabilityStatus: 'supported',
                capabilityReason: 'webgl2_available',
            });
        });

        await act(async () => {
            await result.current.flush();
        });

        expect(fetchMock).toHaveBeenCalledTimes(1);

        const request = fetchMock.mock.calls[0];
        const init = request[1];

        expect(request[0]).toBe('/office-telemetry');
        expect(init?.method).toBe('POST');

        const body = JSON.parse(String(init?.body));

        expect(body.events).toHaveLength(1);
        expect(body.events[0]).toMatchObject({
            type: 'load_succeeded',
            qualityPreset: 'balanced',
            reducedMotion: false,
            capabilityStatus: 'supported',
        });

        expect(body.events[0]).not.toHaveProperty('projectName');
        expect(body.events[0]).not.toHaveProperty('ticketId');
        expect(body.events[0]).not.toHaveProperty('agentId');
        expect(body.events[0]).not.toHaveProperty('gpuRenderer');
        expect(body.events[0]).not.toHaveProperty('userAgent');
    });

    it('returns a failed batch to the bounded queue', async () => {
        const fetchMock = vi
            .spyOn(globalThis, 'fetch')
            .mockRejectedValueOnce(new Error('offline'))
            .mockResolvedValueOnce(
                new Response(null, {
                    status: 202,
                }),
            );

        const { result } = renderHook(() =>
            useOfficeTelemetry('/office-telemetry'),
        );

        act(() => {
            result.current.record({
                type: 'load_failed',
                qualityPreset: 'low',
                reducedMotion: false,
                failureReason: 'context_lost',
            });
        });

        await act(async () => {
            await result.current.flush();
            await result.current.flush();
        });

        expect(fetchMock).toHaveBeenCalledTimes(2);
    });
});
