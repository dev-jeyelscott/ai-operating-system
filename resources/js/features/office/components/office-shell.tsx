import { Link } from '@inertiajs/react';
import {
    Accessibility,
    Box,
    Cuboid,
    Gauge,
    LayoutDashboard,
    ShieldAlert,
} from 'lucide-react';
import {
    lazy,
    Suspense,
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import type { KeyboardEvent } from 'react';
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
import { OfficeAgentInspector } from '@/features/office/components/office-agent-inspector';
import { OfficeNavigation } from '@/features/office/components/office-navigation';
import { OfficeQualityControl } from '@/features/office/components/office-quality-control';
import {
    CanvasLoadingState,
    OfficeCanvasBoundary,
    OfficeRendererFallback,
    RendererCapabilityCheckingState,
    RendererNotLoadedState,
    rendererFailureContent,
    rendererStatusLabel,
} from '@/features/office/components/office-renderer-ui';
import { usePrefersReducedMotion } from '@/features/office/hooks/use-prefers-reduced-motion';
import { useOfficeTelemetry } from '@/features/office/office-telemetry';
import type { OfficeFrameWindow } from '@/features/office/office-telemetry';
import { OFFICE_ZONE_ORDER } from '@/features/office/office-zone-layout';
import {
    DEFAULT_OFFICE_QUALITY_PRESET,
    officeQualityPreset,
} from '@/features/office/quality-presets';
import type { OfficeQualityPresetKey } from '@/features/office/quality-presets';
import type {
    OfficeAgent,
    OfficeProjection,
    OfficeRoomKey,
} from '@/features/office/types';
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
    telemetryEndpointUrl: string;
    rendererCapabilityDetector?: () => OfficeRendererCapability;
};

/**
 * Create a fresh React.lazy boundary so a rejected import can be retried.
 */
function createLazyOfficeCanvas() {
    return lazy(() => import('./office-canvas'));
}

/**
 * Render the DOM-first office and keep keyboard, pointer, and 3D selection in
 * one presentation-state contract.
 */
export function OfficeShell({
    projection,
    operationsUrl,
    telemetryEndpointUrl,
    rendererCapabilityDetector = detectOfficeRendererCapability,
}: Props) {
    const [canvasRequested, setCanvasRequested] = useState(false);
    const [canvasAttempt, setCanvasAttempt] = useState(0);
    const [selectedRoom, setSelectedRoom] = useState<OfficeRoomKey>('lobby');
    const [selectedAgentId, setSelectedAgentId] = useState<string | null>(null);
    const [announcement, setAnnouncement] = useState('');
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

    const inspectorReturnFocusRef = useRef<HTMLElement | null>(null);
    const canvasRegionRef = useRef<HTMLElement | null>(null);
    const reducedMotion = usePrefersReducedMotion();
    const quality = officeQualityPreset(qualityPreset);
    const { record: recordTelemetry } =
        useOfficeTelemetry(telemetryEndpointUrl);
    const lastCapabilityReasonRef = useRef<string | null>(null);

    const visibleRoomKeys = useMemo(
        () =>
            OFFICE_ZONE_ORDER.filter((roomKey) =>
                projection.rooms.some((room) => room.key === roomKey),
            ),
        [projection.rooms],
    );

    const selectedAgent = useMemo<OfficeAgent | null>(
        () =>
            projection.agents.find((agent) => agent.id === selectedAgentId) ??
            null,
        [projection.agents, selectedAgentId],
    );

    /**
     * Remove an inspector selection that no longer exists after projection
     * refresh or reconnect.
     *
     * The state update is deferred so the effect does not synchronously trigger
     * another render while React is processing the committed projection update.
     */
    useEffect(() => {
        if (!selectedAgentId || selectedAgent) {
            return;
        }

        let cancelled = false;

        queueMicrotask(() => {
            if (cancelled) {
                return;
            }

            setSelectedAgentId(null);
            setAnnouncement(
                'The previously selected agent is no longer projected.',
            );
        });

        return () => {
            cancelled = true;
        };
    }, [selectedAgent, selectedAgentId]);

    /**
     * Re-run browser capability detection and apply the safe preset.
     */
    const refreshRendererCapability = useCallback(() => {
        const capability = rendererCapabilityDetector();

        setRendererCapability(capability);

        if (lastCapabilityReasonRef.current !== capability.reason) {
            lastCapabilityReasonRef.current = capability.reason;

            recordTelemetry({
                type: 'capability_checked',
                qualityPreset: capability.recommendedPreset,
                reducedMotion,
                capabilityStatus: capability.status,
                capabilityReason: capability.reason,
            });
        }

        if (capability.recommendedPreset === 'low') {
            setQualityPreset('low');
        }

        if (!capability.canAttempt3d) {
            setCanvasRequested(false);
            setRendererFailure(null);
        }
    }, [recordTelemetry, reducedMotion, rendererCapabilityDetector]);

    /**
     * Detect capability after hydration.
     */
    useEffect(() => {
        let cancelled = false;

        queueMicrotask(() => {
            if (!cancelled) {
                refreshRendererCapability();
            }
        });

        return () => {
            cancelled = true;
        };
    }, [refreshRendererCapability]);

    /**
     * Select one room without mutating workflow truth.
     */
    const selectRoom = useCallback((room: OfficeRoomKey) => {
        setSelectedRoom(room);
        setAnnouncement(`${humanize(room)} selected.`);
    }, []);

    /**
     * Open an agent inspector and remember the exact return-focus target.
     */
    const inspectAgent = useCallback(
        (agentId: string, returnFocusTarget: HTMLElement) => {
            const agent = projection.agents.find(
                (candidate) => candidate.id === agentId,
            );

            if (!agent) {
                return;
            }

            inspectorReturnFocusRef.current = returnFocusTarget;
            setSelectedRoom(agent.room);
            setSelectedAgentId(agent.id);
            setAnnouncement(`${agent.role} inspector opened.`);
        },
        [projection.agents],
    );

    /**
     * Open the inspector from a pointer selection inside the canvas.
     */
    const inspectCanvasAgent = useCallback(
        (agentId: string) => {
            if (!canvasRegionRef.current) {
                return;
            }

            inspectAgent(agentId, canvasRegionRef.current);
        },
        [inspectAgent],
    );

    /**
     * Handle the controlled inspector lifecycle.
     */
    function changeInspectorOpen(open: boolean) {
        if (!open) {
            setSelectedAgentId(null);
            setAnnouncement('Agent inspector closed.');
        }
    }

    /**
     * Apply one-tab-stop canvas-region keyboard navigation.
     */
    function handleCanvasKeyDown(event: KeyboardEvent<HTMLElement>) {
        const currentIndex = visibleRoomKeys.indexOf(selectedRoom);

        if (currentIndex < 0 || visibleRoomKeys.length === 0) {
            return;
        }

        let targetIndex: number | null = null;

        switch (event.key) {
            case 'ArrowRight':
            case 'ArrowDown':
                targetIndex = (currentIndex + 1) % visibleRoomKeys.length;
                break;

            case 'ArrowLeft':
            case 'ArrowUp':
                targetIndex =
                    (currentIndex - 1 + visibleRoomKeys.length) %
                    visibleRoomKeys.length;
                break;

            case 'Home':
                targetIndex = 0;
                break;

            case 'End':
                targetIndex = visibleRoomKeys.length - 1;
                break;

            case 'Enter':
            case ' ': {
                event.preventDefault();

                const firstAgent = projection.agents.find(
                    (agent) => agent.room === selectedRoom,
                );

                if (firstAgent && canvasRegionRef.current) {
                    inspectAgent(firstAgent.id, canvasRegionRef.current);
                } else {
                    setAnnouncement(
                        `${humanize(selectedRoom)} has no projected agents.`,
                    );
                }

                return;
            }
        }

        if (targetIndex === null) {
            return;
        }

        event.preventDefault();
        selectRoom(visibleRoomKeys[targetIndex]);
    }

    /**
     * Load the lazy renderer only after capability detection permits it.
     */
    function loadRenderer() {
        if (!rendererCapability.canAttempt3d) {
            return;
        }

        setRendererFailure(null);
        setCanvasRequested(true);

        recordTelemetry({
            type: 'load_started',
            qualityPreset,
            reducedMotion,
            capabilityStatus: rendererCapability.status,
            capabilityReason: rendererCapability.reason,
        });
    }

    /**
     * Record local renderer failure without changing projection truth.
     */
    const handleRendererFailure = useCallback(
        (reason: OfficeRendererFailureReason) => {
            setRendererFailure(reason);
            setAnnouncement('3D renderer fallback activated.');

            recordTelemetry({
                type: 'load_failed',
                qualityPreset,
                reducedMotion,
                capabilityStatus: rendererCapability.status,
                capabilityReason: rendererCapability.reason,
                failureReason: reason,
            });
        },
        [
            qualityPreset,
            recordTelemetry,
            reducedMotion,
            rendererCapability.reason,
            rendererCapability.status,
        ],
    );

    /**
     * Retry using the least expensive quality preset.
     */
    function retryRenderer() {
        setQualityPreset('low');
        setRendererFailure(null);
        setCanvasRequested(true);
        setCanvasAttempt((attempt) => attempt + 1);
        setLazyOfficeCanvas(createLazyOfficeCanvas());

        recordTelemetry({
            type: 'load_started',
            qualityPreset: 'low',
            reducedMotion,
            capabilityStatus: rendererCapability.status,
            capabilityReason: rendererCapability.reason,
        });
    }

    /**
     * Record successful WebGL initialization.
     */
    const handleRendererReady = useCallback(() => {
        recordTelemetry({
            type: 'load_succeeded',
            qualityPreset,
            reducedMotion,
            capabilityStatus: rendererCapability.status,
            capabilityReason: rendererCapability.reason,
        });
    }, [
        qualityPreset,
        recordTelemetry,
        reducedMotion,
        rendererCapability.reason,
        rendererCapability.status,
    ]);

    /**
     * Record one aggregated frame-health window.
     */
    const handlePerformanceSample = useCallback(
        (frame: OfficeFrameWindow) => {
            recordTelemetry({
                type: 'frame_window',
                qualityPreset,
                reducedMotion,
                capabilityStatus: rendererCapability.status,
                capabilityReason: rendererCapability.reason,
                frame,
            });
        },
        [
            qualityPreset,
            recordTelemetry,
            reducedMotion,
            rendererCapability.reason,
            rendererCapability.status,
        ],
    );

    /**
     * Change presentation quality and record only the selected preset.
     */
    function changeQualityPreset(value: OfficeQualityPresetKey) {
        setQualityPreset(value);

        recordTelemetry({
            type: 'quality_changed',
            qualityPreset: value,
            reducedMotion,
            capabilityStatus: rendererCapability.status,
            capabilityReason: rendererCapability.reason,
        });
    }

    const runtimeFailureCopy = rendererFailure
        ? rendererFailureContent(rendererFailure)
        : null;
    const boundaryFailureCopy = rendererFailureContent('initialization_failed');

    return (
        <div className="space-y-6">
            <p
                className="sr-only"
                role="status"
                aria-live="polite"
                aria-atomic="true"
            >
                {announcement}
            </p>

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
                onSelectRoom={selectRoom}
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
                            onChange={changeQualityPreset}
                        />
                    </div>

                    <p
                        id="office-canvas-keyboard-instructions"
                        className="text-sm text-muted-foreground"
                    >
                        Keyboard: use arrow keys to move between rooms, Home or
                        End to jump, and Enter or Space to inspect the first
                        projected agent in the selected room.
                    </p>

                    <section
                        id="office-canvas-region"
                        ref={canvasRegionRef}
                        tabIndex={0}
                        aria-label="Interactive 3D office navigation"
                        aria-describedby="office-canvas-keyboard-instructions"
                        onKeyDown={handleCanvasKeyDown}
                        className="flex min-h-96 items-center justify-center overflow-hidden rounded-xl border bg-muted/30 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
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
                                            selectedAgentId={selectedAgentId}
                                            reducedMotion={reducedMotion}
                                            qualityPreset={qualityPreset}
                                            onSelectRoom={selectRoom}
                                            onSelectAgent={inspectCanvasAgent}
                                            onRendererFailure={
                                                handleRendererFailure
                                            }
                                            onRendererReady={
                                                handleRendererReady
                                            }
                                            onPerformanceSample={
                                                handlePerformanceSample
                                            }
                                        />
                                    </div>
                                </Suspense>
                            </OfficeCanvasBoundary>
                        )}
                    </section>

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
                selectedAgentId={selectedAgentId}
                onSelectRoom={selectRoom}
                onInspectAgent={inspectAgent}
            />

            <OfficeAgentInspector
                agent={selectedAgent}
                open={selectedAgent !== null}
                returnFocusRef={inspectorReturnFocusRef}
                onOpenChange={changeInspectorOpen}
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
 * Convert enum-style values into readable labels.
 */
function humanize(value: string) {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

/**
 * Format an ISO timestamp as deterministic UTC text.
 */
function formatDate(value: string) {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'Unknown projection time';
    }

    return `${date.toISOString().slice(0, 16).replace('T', ' ')} UTC`;
}
