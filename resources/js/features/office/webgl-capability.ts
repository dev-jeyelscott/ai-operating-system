import type { OfficeQualityPresetKey } from '@/features/office/quality-presets';

export type OfficeRendererCapabilityStatus =
    'checking' | 'supported' | 'limited' | 'unavailable';

export type OfficeRendererCapabilityReason =
    | 'not_checked'
    | 'webgl2_available'
    | 'major_performance_caveat'
    | 'webgl2_unavailable'
    | 'capability_check_failed';

export type OfficeRendererFailureReason =
    'webgl_unavailable' | 'initialization_failed' | 'context_lost';

export type OfficeRendererCapability = {
    status: OfficeRendererCapabilityStatus;
    reason: OfficeRendererCapabilityReason;
    canAttempt3d: boolean;
    recommendedPreset: OfficeQualityPresetKey;
    title: string;
    description: string;
};

type OfficeRendererCapabilityDocument = {
    createElement: (tagName: 'canvas') => HTMLCanvasElement;
};

export const CHECKING_OFFICE_RENDERER_CAPABILITY = {
    status: 'checking',
    reason: 'not_checked',
    canAttempt3d: false,
    recommendedPreset: 'balanced',
    title: 'Checking 3D renderer support',
    description:
        'The browser is being checked for the WebGL 2 support required by the interactive office.',
} satisfies OfficeRendererCapability;

export const SUPPORTED_OFFICE_RENDERER_CAPABILITY = {
    status: 'supported',
    reason: 'webgl2_available',
    canAttempt3d: true,
    recommendedPreset: 'balanced',
    title: '3D renderer supported',
    description:
        'WebGL 2 is available without a reported major performance caveat.',
} satisfies OfficeRendererCapability;

export const LIMITED_OFFICE_RENDERER_CAPABILITY = {
    status: 'limited',
    reason: 'major_performance_caveat',
    canAttempt3d: true,
    recommendedPreset: 'low',
    title: 'Low-capability mode enabled',
    description:
        'WebGL 2 is available, but the browser reported a major performance caveat. The office will start with Low rendering quality.',
} satisfies OfficeRendererCapability;

export const UNAVAILABLE_OFFICE_RENDERER_CAPABILITY = {
    status: 'unavailable',
    reason: 'webgl2_unavailable',
    canAttempt3d: false,
    recommendedPreset: 'low',
    title: '3D office unavailable',
    description:
        'This browser or device could not initialize the WebGL 2 renderer required by the interactive office. The operational dashboard and accessible office controls remain available.',
} satisfies OfficeRendererCapability;

export const FAILED_OFFICE_RENDERER_CAPABILITY = {
    status: 'unavailable',
    reason: 'capability_check_failed',
    canAttempt3d: false,
    recommendedPreset: 'low',
    title: '3D capability check failed',
    description:
        'The browser could not safely complete the 3D capability check. The operational dashboard and accessible office controls remain available.',
} satisfies OfficeRendererCapability;

/**
 * Detect whether the browser can initialize the WebGL 2 renderer required by
 * the installed Three.js version.
 *
 * The first probe rejects browser-reported major performance caveats. When
 * only the relaxed probe succeeds, the device is classified as limited and
 * the existing Low quality preset is recommended.
 */
export function detectOfficeRendererCapability(
    documentObject: OfficeRendererCapabilityDocument | null = typeof document ===
    'undefined'
        ? null
        : (document as OfficeRendererCapabilityDocument),
): OfficeRendererCapability {
    if (!documentObject) {
        return CHECKING_OFFICE_RENDERER_CAPABILITY;
    }

    try {
        const preferredContext = createWebGl2Context(documentObject, {
            antialias: false,
            failIfMajorPerformanceCaveat: true,
            powerPreference: 'high-performance',
        });

        if (preferredContext) {
            releaseProbeContext(preferredContext);

            return SUPPORTED_OFFICE_RENDERER_CAPABILITY;
        }

        const limitedContext = createWebGl2Context(documentObject, {
            antialias: false,
            failIfMajorPerformanceCaveat: false,
            powerPreference: 'low-power',
        });

        if (limitedContext) {
            releaseProbeContext(limitedContext);

            return LIMITED_OFFICE_RENDERER_CAPABILITY;
        }

        return UNAVAILABLE_OFFICE_RENDERER_CAPABILITY;
    } catch {
        return FAILED_OFFICE_RENDERER_CAPABILITY;
    }
}

/**
 * Create one temporary WebGL 2 context for capability classification.
 */
function createWebGl2Context(
    documentObject: OfficeRendererCapabilityDocument,
    attributes: WebGLContextAttributes,
): WebGL2RenderingContext | null {
    const canvas = documentObject.createElement('canvas');

    return canvas.getContext('webgl2', attributes);
}

/**
 * Release the temporary probe context when the browser exposes the standard
 * context-loss extension.
 *
 * This prevents a capability probe from retaining an unnecessary GPU context
 * before the real React Three Fiber Canvas is requested.
 */
function releaseProbeContext(context: WebGL2RenderingContext): void {
    context.getExtension('WEBGL_lose_context')?.loseContext();
}
