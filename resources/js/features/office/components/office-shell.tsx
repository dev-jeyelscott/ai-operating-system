import { Link } from '@inertiajs/react';
import {
    Box,
    Cuboid,
    LayoutDashboard,
    LoaderCircle,
    ShieldAlert,
} from 'lucide-react';
import { Component, lazy, Suspense, useState } from 'react';
import type { ErrorInfo, ReactNode } from 'react';
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
import { OfficeNavigation } from '@/features/office/components/office-navigation';
import type { OfficeProjection, OfficeRoomKey } from '@/features/office/types';

const LazyOfficeCanvas = lazy(() => import('./office-canvas'));

type Props = {
    projection: OfficeProjection;
    operationsUrl: string;
};

/**
 * Render the DOM-first office shell and coordinate presentation-only room
 * selection between the accessible navigator and the lazy 3D scene.
 */
export function OfficeShell({ projection, operationsUrl }: Props) {
    const [canvasRequested, setCanvasRequested] = useState(false);
    const [selectedRoom, setSelectedRoom] = useState<OfficeRoomKey>('lobby');

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
                                Select a room through the keyboard-accessible
                                zone controls or the 3D floor plan.
                            </CardDescription>
                        </div>

                        <Badge variant="outline">
                            Schema v{projection.metadata.schemaVersion}
                        </Badge>
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
                                        The room navigator is already usable.
                                        Load the scene only when the visual
                                        office is needed.
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
                                            onSelectRoom={setSelectedRoom}
                                        />
                                    </div>
                                </Suspense>
                            </OfficeCanvasBoundary>
                        )}
                    </div>

                    <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground">
                        <p>
                            Selected room:{' '}
                            {selectedRoom
                                .replaceAll('_', ' ')
                                .replace(/\b\w/g, (character) =>
                                    character.toUpperCase(),
                                )}
                            . Projected{' '}
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
 * Preserve the page shell if the lazy 3D module fails to render.
 */
class OfficeCanvasBoundary extends Component<
    { children: ReactNode },
    { failed: boolean }
> {
    state = {
        failed: false,
    };

    /**
     * Mark only the 3D region as failed.
     */
    static getDerivedStateFromError() {
        return {
            failed: true,
        };
    }

    /**
     * Leave structured reporting to the application error pipeline.
     */
    componentDidCatch(_error: Error, _info: ErrorInfo) {}

    /**
     * Render the lazy Canvas or a local fallback.
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
                        Continue through the room navigator or accessible
                        operational dashboard.
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
 * Format an ISO date without modifying projection truth.
 */
function formatDate(value: string) {
    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}
