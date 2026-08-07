import { describe, expect, it, vi } from 'vitest';
import {
    CHECKING_OFFICE_RENDERER_CAPABILITY,
    FAILED_OFFICE_RENDERER_CAPABILITY,
    LIMITED_OFFICE_RENDERER_CAPABILITY,
    SUPPORTED_OFFICE_RENDERER_CAPABILITY,
    UNAVAILABLE_OFFICE_RENDERER_CAPABILITY,
    detectOfficeRendererCapability,
} from '@/features/office/webgl-capability';

/**
 * Build the minimum WebGL 2 context contract required by the capability probe.
 */
function createWebGl2Context() {
    const loseContext = vi.fn();

    const context = {
        getExtension: vi.fn((name: string) => {
            if (name !== 'WEBGL_lose_context') {
                return null;
            }

            return {
                loseContext,
                restoreContext: vi.fn(),
            };
        }),
    } as unknown as WebGL2RenderingContext;

    return {
        context,
        loseContext,
    };
}

/**
 * Build a deterministic document and canvas context queue for one test.
 */
function createCapabilityDocument(
    contexts: Array<WebGL2RenderingContext | null>,
) {
    const contextQueue = [...contexts];

    const getContext = vi.fn(() => contextQueue.shift() ?? null);

    const createElement = vi.fn(
        () =>
            ({
                getContext,
            }) as unknown as HTMLCanvasElement,
    );

    return {
        documentObject: {
            createElement,
        },
        createElement,
        getContext,
    };
}

describe('detectOfficeRendererCapability', () => {
    it('returns checking when browser document access is unavailable', () => {
        expect(detectOfficeRendererCapability(null)).toEqual(
            CHECKING_OFFICE_RENDERER_CAPABILITY,
        );
    });

    it('classifies WebGL 2 without a major caveat as supported', () => {
        const preferred = createWebGl2Context();

        const { documentObject, getContext } = createCapabilityDocument([
            preferred.context,
        ]);

        expect(detectOfficeRendererCapability(documentObject)).toEqual(
            SUPPORTED_OFFICE_RENDERER_CAPABILITY,
        );

        expect(getContext).toHaveBeenCalledWith(
            'webgl2',
            expect.objectContaining({
                failIfMajorPerformanceCaveat: true,
                powerPreference: 'high-performance',
            }),
        );

        expect(preferred.loseContext).toHaveBeenCalledTimes(1);
    });

    it('classifies a browser-reported major performance caveat as limited', () => {
        const limited = createWebGl2Context();

        const { documentObject, getContext } = createCapabilityDocument([
            null,
            limited.context,
        ]);

        expect(detectOfficeRendererCapability(documentObject)).toEqual(
            LIMITED_OFFICE_RENDERER_CAPABILITY,
        );

        expect(getContext).toHaveBeenNthCalledWith(
            1,
            'webgl2',
            expect.objectContaining({
                failIfMajorPerformanceCaveat: true,
            }),
        );

        expect(getContext).toHaveBeenNthCalledWith(
            2,
            'webgl2',
            expect.objectContaining({
                failIfMajorPerformanceCaveat: false,
                powerPreference: 'low-power',
            }),
        );

        expect(limited.loseContext).toHaveBeenCalledTimes(1);
    });

    it('classifies the renderer as unavailable when WebGL 2 cannot initialize', () => {
        const { documentObject, getContext } = createCapabilityDocument([
            null,
            null,
        ]);

        expect(detectOfficeRendererCapability(documentObject)).toEqual(
            UNAVAILABLE_OFFICE_RENDERER_CAPABILITY,
        );

        expect(getContext).toHaveBeenCalledTimes(2);
    });

    it('fails safely when the browser capability probe throws', () => {
        const documentObject = {
            createElement: vi.fn(() => {
                throw new Error('Canvas creation failed.');
            }),
        };

        expect(detectOfficeRendererCapability(documentObject)).toEqual(
            FAILED_OFFICE_RENDERER_CAPABILITY,
        );
    });
});
