import { Link } from '@inertiajs/react';
import {
    Accessibility,
    Box,
    Cuboid,
    Gauge,
    LayoutDashboard,
    LoaderCircle,
    RotateCcw,
    ShieldAlert,
    TriangleAlert,
} from 'lucide-react';
import {
    Component,
    lazy,
    Suspense,
    useCallback,
    useEffect,
    useState,
} from 'react';
import type { ReactNode } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { AgentStatusPanel } from '@/features/office/components/agent-status-panel';
import { OfficeNavigation } from '@/features/office/components/office-navigation';
import { OfficeQualityControl } from '@/features/office/components/office-quality-control';
import { usePrefersReducedMotion } from '@/features/office/hooks/use-prefers-reduced-motion';
import {
    DEFAULT_OFFICE_QUALITY_PRESET,
    officeQualityPreset,
} from '@/features/office/quality-presets';
import type { OfficeQualityPresetKey } from '@/features/office/quality-presets';
import type { OfficeProjection, OfficeRoomKey } from '@/features/office/types';
import {
    CHECKING_OFFICE_RENDERER_CAPABILITY,
    detectOfficeRendererCapability,
} from '@/features/office/webgl-capability';
import type {
    OfficeRendererCapability,
    OfficeRendererFailureReason,
} from '@/features/office/webgl-capability';

type Props = {
    projection: OfficeProjection;
    operationsUrl: string;
    rendererCapabilityDetector?: () => OfficeRendererCapability;
};

/**
 * Create a new React.lazy boundary for the 3D module.
 *
 * A fresh lazy component allows an explicit retry after a rejected dynamic
 * import instead of reusing React.lazy's cached rejection.
 */
function createLazyOfficeCanvas() {
    return lazy(() => import('./office-canvas'));
}

/**
 * Render the DOM-first office shell and coordinate presentation state across
 * the accessible controls, agent list, and lazy 3D scene.
 */
export function OfficeShell({
    projection,
    operationsUrl,
    rendererCapabilityDetector = detectOfficeRendererCapability,
}: Props) {
    const [canvasRequested, setCanvasRequested] = useState(false);
    const [canvasAttempt, setCanvasAttempt] = useState(0);
    const [selectedRoom, setSelectedRoom] = useState<OfficeRoomKey>('lobby');
    const [qualityPreset, setQualityPreset] = useState<OfficeQualityPresetKey>(
        DEFAULT_OFFICE_QUALITY_PRESET,
    );

    const [rendererCapability, setRendererCapability] =
        useState<OfficeRendererCapability>(CHECKING_OFFICE_RENDERER_CAPABILITY);

    const [rendererFailure, setRendererFailure] =
        useState<OfficeRendererFailureReason | null>(null);

    const [LazyOfficeCanvas, setLazyOfficeCanvas] = useState(
        createLazyOfficeCanvas,
    );

    const reducedMotion = usePrefersReducedMotion();
    const quality = officeQualityPreset(qualityPreset);

    /**
     * Re-run the browser capability probe and apply its safe recommended
     * presentation state.
     */
    const refreshRendererCapability = useCallback(() => {
        const capability = rendererCapabilityDetector();

        setRendererCapability(capability);

        if (capability.recommendedPreset === 'low') {
            setQualityPreset('low');
        }

        if (!capability.canAttempt3d) {
            setCanvasRequested(false);
            setRendererFailure(null);
        }
    }, [rendererCapabilityDetector]);

    useEffect(() => {
        refreshRendererCapability();
    }, [refreshRendererCapability]);

    /**
     * Load the 3D bundle only after capability detection permits an attempt.
     */
    function loadRenderer() {
        if (!rendererCapability.canAttempt3d) {
            return;
        }

        setRendererFailure(null);
        setCanvasRequested(true);
    }

    /**
     * Record a local renderer failure without changing authoritative workflow
     * or projection state.
     */
    const handleRendererFailure = useCallback(
        (reason: OfficeRendererFailureReason) => {
            setRendererFailure(reason);
        },
        [],
    );

    /**
     * Retry renderer initialization with the least expensive configuration.
     *
     * A new React.lazy instance is created so a previously rejected module
     * import can be attempted again.
     */
    function retryRenderer() {
        setQualityPreset('low');
        setRendererFailure(null);
        setCanvasRequested(true);
        setCanvasAttempt((currentAttempt) => currentAttempt + 1);
        setLazyOfficeCanvas(createLazyOfficeCanvas());
    }

    const runtimeFailureCopy = rendererFailure
        ? rendererFailureContent(rendererFailure)
        : null;

    const boundaryFailureCopy = rendererFailureContent('initialization_failed');

    return (
        <div className="space-y-6">
            {projection.simulation.labelRequired && (
                <Alert>
                    <ShieldAlert aria-hidden="true" />
                    <AlertTitle>Simulation remains unverified</AlertTitle>
                    <AlertDescription>
                        This office visualizes simulated workflow activity. It
                        does not represent verified repository execution, CI,
                        QA, merge, or deployment evidence.
                    </AlertDescription>
                </Alert>
            )}

            {reducedMotion && (
                <Alert>
                    <Accessibility aria-hidden="true" />
                    <AlertTitle>Reduced motion enabled</AlertTitle>
                    <AlertDescription>
                        Camera and agent transitions update immediately. The
                        office disables travel, bobbing, pulsing, and turning
                        effects while preserving the same workflow state and
                        controls.
                    </AlertDescription>
                </Alert>
            )}

            {rendererCapability.status === 'limited' && (
                <Alert>
                    <Gauge aria-hidden="true" />
                    <AlertTitle>{rendererCapability.title}</AlertTitle>
                    <AlertDescription>
                        {rendererCapability.description}
                    </AlertDescription>
                </Alert>
            )}

            <section
                aria-labelledby="office-projection-summary-heading"
                className="grid gap-4 md:grid-cols-2 xl:grid-cols-4"
            >
                <h2 id="office-projection-summary-heading" className="sr-only">
                    Office projection summary
                </h2>

                <SummaryCard
                    label="Rooms"
                    value={projection.rooms.length}
                    icon={Box}
                />

                <SummaryCard
                    label="Projected agents"
                    value={projection.agents.length}
                    icon={Cuboid}
                />

                <SummaryCard
                    label="Active agents"
                    value={projection.summary.activeAgents}
                    icon={Cuboid}
                />

                <SummaryCard
                    label="Projection sequence"
                    value={projection.metadata.lastEventSequence}
                    icon={Box}
                />
            </section>

            <OfficeNavigation
                rooms={projection.rooms}
                selectedRoom={selectedRoom}
                onSelectRoom={setSelectedRoom}
            />

            <Card>
                <CardHeader className="gap-3">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <CardTitle>Interactive office</CardTitle>
                            <CardDescription>
                                Room and agent visuals are derived only from the
                                persisted office projection. Renderer capability
                                and quality change presentation only.
                            </CardDescription>
                        </div>

                        <div className="flex flex-wrap gap-2">
                            <Badge variant="outline">
                                Schema v{projection.metadata.schemaVersion}
                            </Badge>

                            <Badge variant="outline">
                                {reducedMotion
                                    ? 'Reduced motion'
                                    : 'Motion enabled'}
                            </Badge>

                            <Badge variant="outline">
                                {quality.label} quality
                            </Badge>

                            <Badge variant="outline">
                                {rendererStatusLabel(
                                    rendererCapability,
                                    rendererFailure,
                                )}
                            </Badge>
                        </div>
                    </div>
                </CardHeader>

                <CardContent className="space-y-4">
                    <div className="rounded-lg border bg-muted/20 p-4">
                        <OfficeQualityControl
                            value={qualityPreset}
                            disabled={!rendererCapability.canAttempt3d}
                            onChange={setQualityPreset}
                        />
                    </div>

                    <div
                        className="flex min-h-96 items-center justify-center overflow-hidden rounded-xl border bg-muted/30"
                        data-testid="office-canvas-container"
                    >
                        {rendererCapability.status === 'checking' ? (
                            <RendererCapabilityCheckingState />
                        ) : rendererCapability.status === 'unavailable' ? (
                            <OfficeRendererFallback
                                title={rendererCapability.title}
                                description={rendererCapability.description}
                                operationsUrl={operationsUrl}
                                retryLabel="Check WebGL again"
                                onRetry={refreshRendererCapability}
                            />
                        ) : runtimeFailureCopy ? (
                            <OfficeRendererFallback
                                title={runtimeFailureCopy.title}
                                description={runtimeFailureCopy.description}
                                operationsUrl={operationsUrl}
                                retryLabel="Retry with Low quality"
                                onRetry={retryRenderer}
                            />
                        ) : !canvasRequested ? (
                            <RendererNotLoadedState
                                qualityLabel={quality.label}
                                onLoad={loadRenderer}
                            />
                        ) : (
                            <OfficeCanvasBoundary
                                key={`${canvasAttempt}:${qualityPreset}`}
                                fallback={
                                    <OfficeRendererFallback
                                        title={boundaryFailureCopy.title}
                                        description={
                                            boundaryFailureCopy.description
                                        }
                                        operationsUrl={operationsUrl}
                                        retryLabel="Retry with Low quality"
                                        onRetry={retryRenderer}
                                    />
                                }
                                onFailure={handleRendererFailure}
                            >
                                <Suspense fallback={<CanvasLoadingState />}>
                                    <div
                                        className="h-[min(70vh,48rem)] w-full"
                                        data-testid="office-canvas-loaded"
                                    >
                                        <LazyOfficeCanvas
                                            projection={projection}
                                            selectedRoom={selectedRoom}
                                            reducedMotion={reducedMotion}
                                            qualityPreset={qualityPreset}
                                            onSelectRoom={setSelectedRoom}
                                            onRendererFailure={
                                                handleRendererFailure
                                            }
                                        />
                                    </div>
                                </Suspense>
                            </OfficeCanvasBoundary>
                        )}
                    </div>

                    <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground">
                        <p>
                            Selected room: {humanize(selectedRoom)}. Projected{' '}
                            {formatDate(projection.metadata.projectedAt)}.
                        </p>

                        <Button asChild variant="outline">
                            <Link href={operationsUrl}>
                                <LayoutDashboard aria-hidden="true" />
                                Open accessible dashboard
                            </Link>
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <AgentStatusPanel
                agents={projection.agents}
                selectedRoom={selectedRoom}
                onSelectRoom={setSelectedRoom}
            />
        </div>
    );
}

/**
 * Render one numeric projection summary card.
 */
function SummaryCard({
    label,
    value,
    icon: Icon,
}: {
    label: string;
    value: number;
    icon: typeof Box;
}) {
    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between gap-3 pb-2">
                <CardTitle className="text-sm font-medium">{label}</CardTitle>

                <Icon
                    className="size-4 text-muted-foreground"
                    aria-hidden="true"
                />
            </CardHeader>

            <CardContent>
                <p className="text-3xl font-semibold tabular-nums">{value}</p>
            </CardContent>
        </Card>
    );
}

/**
 * Render an accessible state while the browser capability probe is running.
 */
function RendererCapabilityCheckingState() {
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
 * Render the user-controlled pre-load state after WebGL capability passes.
 */
function RendererNotLoadedState({
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
 * Render the operational fallback without hiding authoritative projection
 * summaries, room navigation, or logical-agent state.
 */
function OfficeRendererFallback({
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
 * Preserve the complete page shell when the lazy 3D module or renderer throws.
 */
class OfficeCanvasBoundary extends Component<
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
     * Replace only the Canvas region after a descendant rendering error.
     */
    static getDerivedStateFromError() {
        return {
            failed: true,
        };
    }

    /**
     * Notify the DOM-first parent that renderer initialization failed.
     */
    componentDidCatch() {
        this.props.onFailure('initialization_failed');
    }

    /**
     * Render either the Canvas subtree or its accessible replacement.
     */
    render() {
        if (this.state.failed) {
            return this.props.fallback;
        }

        return this.props.children;
    }
}

/**
 * Render an accessible Suspense fallback while the lazy 3D bundle loads.
 */
function CanvasLoadingState() {
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
 * Return user-facing fallback content for a renderer runtime failure.
 */
function rendererFailureContent(reason: OfficeRendererFailureReason) {
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
 * Return a concise renderer status label for the card metadata.
 */
function rendererStatusLabel(
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

/**
 * Convert enum-style values into readable labels.
 */
function humanize(value: string) {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

/**
 * Format an ISO date without modifying projection truth.
 */
function formatDate(value: string) {
    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}
