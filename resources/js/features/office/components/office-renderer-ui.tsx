import { Link } from '@inertiajs/react';
import {
    Cuboid,
    LayoutDashboard,
    LoaderCircle,
    RotateCcw,
    TriangleAlert,
} from 'lucide-react';
import { Component } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import type {
    OfficeRendererCapability,
    OfficeRendererFailureReason,
} from '@/features/office/webgl-capability';

/**
 * Render an accessible status while browser capability detection runs.
 */
export function RendererCapabilityCheckingState() {
    return (
        <div
            role="status"
            className="flex min-h-96 flex-col items-center justify-center gap-3 p-8 text-center"
        >
            <LoaderCircle className="size-7 animate-spin" aria-hidden="true" />

            <div>
                <h3 className="font-medium">Checking 3D renderer support</h3>

                <p className="mt-2 max-w-lg text-sm text-muted-foreground">
                    The accessible room navigator and agent list remain
                    available while WebGL 2 capability is checked.
                </p>
            </div>
        </div>
    );
}

/**
 * Render the user-controlled state before the lazy 3D bundle is loaded.
 */
export function RendererNotLoadedState({
    qualityLabel,
    onLoad,
}: {
    qualityLabel: string;
    onLoad: () => void;
}) {
    return (
        <div className="max-w-lg space-y-4 p-8 text-center">
            <Cuboid
                className="mx-auto size-10 text-muted-foreground"
                aria-hidden="true"
            />

            <div>
                <h3 className="font-medium">3D renderer not loaded</h3>

                <p className="mt-2 text-sm text-muted-foreground">
                    The office is ready to load with {qualityLabel} rendering
                    quality. Agent state is already available in the accessible
                    list below.
                </p>
            </div>

            <Button type="button" onClick={onLoad}>
                Load 3D office
            </Button>
        </div>
    );
}

/**
 * Render the operational fallback while retaining all DOM controls.
 */
export function OfficeRendererFallback({
    title,
    description,
    operationsUrl,
    retryLabel,
    onRetry,
}: {
    title: string;
    description: string;
    operationsUrl: string;
    retryLabel?: string;
    onRetry?: () => void;
}) {
    return (
        <div role="alert" className="max-w-xl space-y-4 p-8 text-center">
            <TriangleAlert
                className="mx-auto size-10 text-muted-foreground"
                aria-hidden="true"
            />

            <div>
                <h3 className="font-medium">{title}</h3>

                <p className="mt-2 text-sm text-muted-foreground">
                    {description}
                </p>
            </div>

            <div className="flex flex-wrap justify-center gap-2">
                {onRetry && retryLabel && (
                    <Button type="button" variant="outline" onClick={onRetry}>
                        <RotateCcw aria-hidden="true" />
                        {retryLabel}
                    </Button>
                )}

                <Button asChild>
                    <Link href={operationsUrl}>
                        <LayoutDashboard aria-hidden="true" />
                        Continue in operational dashboard
                    </Link>
                </Button>
            </div>
        </div>
    );
}

/**
 * Preserve the page shell when the lazy module or renderer throws.
 */
export class OfficeCanvasBoundary extends Component<
    {
        children: ReactNode;
        fallback: ReactNode;
        onFailure: (reason: OfficeRendererFailureReason) => void;
    },
    {
        failed: boolean;
    }
> {
    state = {
        failed: false,
    };

    /**
     * Replace only the failed canvas subtree.
     */
    static getDerivedStateFromError() {
        return {
            failed: true,
        };
    }

    /**
     * Notify the DOM-first shell about renderer initialization failure.
     */
    componentDidCatch() {
        this.props.onFailure('initialization_failed');
    }

    /**
     * Render the canvas or its accessible replacement.
     */
    render() {
        return this.state.failed ? this.props.fallback : this.props.children;
    }
}

/**
 * Render an accessible Suspense state while the 3D bundle loads.
 */
export function CanvasLoadingState() {
    return (
        <div
            role="status"
            className="flex min-h-96 flex-col items-center justify-center gap-3"
        >
            <LoaderCircle className="size-7 animate-spin" aria-hidden="true" />

            <p className="text-sm text-muted-foreground">
                Loading the 3D office renderer…
            </p>
        </div>
    );
}

/**
 * Return user-facing content for a renderer failure.
 */
export function rendererFailureContent(reason: OfficeRendererFailureReason) {
    switch (reason) {
        case 'context_lost':
            return {
                title: 'The 3D rendering context was lost',
                description:
                    'The browser or graphics device stopped the active WebGL context. The office projection remains available through the accessible controls and operational dashboard.',
            };

        case 'webgl_unavailable':
            return {
                title: 'The 3D renderer became unavailable',
                description:
                    'The browser could not create the required WebGL 2 renderer. The office projection remains available through the accessible controls and operational dashboard.',
            };

        case 'initialization_failed':
            return {
                title: 'The 3D office could not be initialized',
                description:
                    'The renderer or its lazy-loaded module failed during initialization. Continue with the accessible dashboard or retry using Low rendering quality.',
            };
    }
}

/**
 * Return a concise renderer status label.
 */
export function rendererStatusLabel(
    capability: OfficeRendererCapability,
    failure: OfficeRendererFailureReason | null,
) {
    if (failure) {
        return 'Fallback active';
    }

    switch (capability.status) {
        case 'checking':
            return 'Checking WebGL';

        case 'supported':
            return 'WebGL supported';

        case 'limited':
            return 'Low-capability mode';

        case 'unavailable':
            return 'Dashboard fallback';
    }
}
