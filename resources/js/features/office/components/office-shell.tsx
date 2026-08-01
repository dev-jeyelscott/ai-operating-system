import { Link } from '@inertiajs/react';
import {
    Accessibility,
    Box,
    Cuboid,
    LayoutDashboard,
    LoaderCircle,
    ShieldAlert,
} from 'lucide-react';
import { Component, lazy, Suspense, useState } from 'react';
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
import { usePrefersReducedMotion } from '@/features/office/hooks/use-prefers-reduced-motion';
import type { OfficeProjection, OfficeRoomKey } from '@/features/office/types';

const LazyOfficeCanvas = lazy(() => import('./office-canvas'));

type Props = {
    projection: OfficeProjection;
    operationsUrl: string;
};

/**
 * Render the DOM-first office shell and coordinate room focus across the
 * accessible navigation, agent list, and lazy 3D scene.
 */
export function OfficeShell({ projection, operationsUrl }: Props) {
    const [canvasRequested, setCanvasRequested] = useState(false);
    const [selectedRoom, setSelectedRoom] = useState<OfficeRoomKey>('lobby');
    const reducedMotion = usePrefersReducedMotion();

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
                                persisted office projection.
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
                        </div>
                    </div>
                </CardHeader>

                <CardContent className="space-y-4">
                    <div
                        className="flex min-h-96 items-center justify-center overflow-hidden rounded-xl border bg-muted/30"
                        data-testid="office-canvas-container"
                    >
                        {!canvasRequested ? (
                            <div className="max-w-lg space-y-4 p-8 text-center">
                                <Cuboid
                                    className="mx-auto size-10 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <div>
                                    <h3 className="font-medium">
                                        3D renderer not loaded
                                    </h3>
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        Agent state is already available in the
                                        accessible list below.
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    onClick={() => setCanvasRequested(true)}
                                >
                                    Load 3D office
                                </Button>
                            </div>
                        ) : (
                            <OfficeCanvasBoundary>
                                <Suspense fallback={<CanvasLoadingState />}>
                                    <div
                                        className="h-[min(70vh,48rem)] w-full"
                                        data-testid="office-canvas-loaded"
                                    >
                                        <LazyOfficeCanvas
                                            projection={projection}
                                            selectedRoom={selectedRoom}
                                            reducedMotion={reducedMotion}
                                            onSelectRoom={setSelectedRoom}
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
 * Preserve the page shell if the lazy 3D module fails.
 */
class OfficeCanvasBoundary extends Component<
    { children: ReactNode },
    { failed: boolean }
> {
    state = {
        failed: false,
    };

    /**
     * Mark only the Canvas region as failed.
     */
    static getDerivedStateFromError() {
        return {
            failed: true,
        };
    }

    /**
     * Leave structured reporting to the application error pipeline.
     */
    componentDidCatch() {}

    /**
     * Render the Canvas or an accessible local fallback.
     */
    render() {
        if (this.state.failed) {
            return (
                <div
                    role="alert"
                    className="max-w-lg space-y-2 p-8 text-center"
                >
                    <h3 className="font-medium">
                        The 3D office could not be loaded
                    </h3>
                    <p className="text-sm text-muted-foreground">
                        Continue through the room navigator, logical-agent list,
                        or operational dashboard.
                    </p>
                </div>
            );
        }

        return this.props.children;
    }
}

/**
 * Render an accessible Suspense fallback.
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
