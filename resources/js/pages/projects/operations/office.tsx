import { Head, Link, router, usePoll } from '@inertiajs/react';
import { ArrowLeft, LayoutDashboard, RefreshCw } from 'lucide-react';
import { useCallback, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { OfficeActivityFeed } from '@/features/office/components/office-activity-feed';
import { OfficeShell } from '@/features/office/components/office-shell';
import { useOfficeProjectionStream } from '@/features/office/hooks/use-office-projection-stream';
import type { OfficeProjection } from '@/features/office/types';

type Props = {
    organization: {
        id: number;
        name: string;
        slug: string;
    };
    project: {
        id: number;
        name: string;
        slug: string;
        status: string;
    };
    projectUrl: string;
    operationsUrl: string;
    officeProjectionEndpointUrl: string;
    officeTelemetryEndpointUrl: string;
    officeProjection: OfficeProjection;
};

/**
 * Render the tenant-scoped office from the persisted backend projection.
 *
 * Reverb provides low-latency invalidation only. Inertia polling remains the
 * recovery path for dropped delivery and reconnect.
 */
export default function ProjectOffice({
    organization,
    project,
    projectUrl,
    operationsUrl,
    officeProjectionEndpointUrl,
    officeTelemetryEndpointUrl,
    officeProjection,
}: Props) {
    const [refreshing, setRefreshing] = useState(false);

    /**
     * Reload only the durable projection and preserve current UI context.
     */
    const refreshProjection = useCallback(() => {
        router.reload({
            only: ['officeProjection'],
            preserveScroll: true,
            preserveState: true,
            onStart: () => setRefreshing(true),
            onFinish: () => setRefreshing(false),
        });
    }, []);

    /*
     * Keep the existing bounded poll. It guarantees state convergence if a
     * WebSocket event is missed while the browser is disconnected.
     */
    usePoll(10_000, {
        only: ['officeProjection'],
        preserveScroll: true,
        preserveState: true,
        onStart: () => setRefreshing(true),
        onFinish: () => setRefreshing(false),
    });

    /*
     * A newer sequence from Reverb triggers the same durable projection reload.
     * Provider messages never directly change workflow state in React.
     */
    useOfficeProjectionStream({
        organizationId: organization.id,
        projectId: project.id,
        lastEventSequence:
            officeProjection.metadata.lastEventSequence,
        onNewerProjection: refreshProjection,
    });

    return (
        <>
            <Head title={`${project.name} office`} />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <Button asChild variant="ghost" size="sm">
                            <Link href={projectUrl}>
                                <ArrowLeft aria-hidden="true" />
                                Back to project
                            </Link>
                        </Button>

                        <div className="mt-4 flex flex-wrap items-center gap-3">
                            <h1 className="text-2xl font-semibold tracking-tight">
                                Interactive office
                            </h1>

                            <Badge variant="outline">
                                {humanize(project.status)}
                            </Badge>

                            <Badge variant="outline">
                                Sequence{' '}
                                {
                                    officeProjection.metadata
                                        .lastEventSequence
                                }
                            </Badge>
                        </div>

                        <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                            Projection-driven visualization for {project.name}{' '}
                            in {organization.name}. Provider activity is derived
                            from durable backend events rather than browser
                            timers.
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <Link href={operationsUrl}>
                                <LayoutDashboard aria-hidden="true" />
                                Operational dashboard
                            </Link>
                        </Button>

                        <Button
                            type="button"
                            variant="outline"
                            onClick={refreshProjection}
                            disabled={refreshing}
                        >
                            <RefreshCw
                                aria-hidden="true"
                                className={
                                    refreshing ? 'animate-spin' : ''
                                }
                            />

                            {refreshing ? 'Refreshing…' : 'Refresh'}
                        </Button>
                    </div>
                </header>

                <div
                    role="status"
                    aria-live="polite"
                    className="text-sm text-muted-foreground"
                >
                    {refreshing
                        ? 'Refreshing office projection.'
                        : `Durable projection sequence ${officeProjection.metadata.lastEventSequence}. Endpoint: ${officeProjectionEndpointUrl}`}
                </div>

                <OfficeShell
                    projection={officeProjection}
                    operationsUrl={operationsUrl}
                    telemetryEndpointUrl={
                        officeTelemetryEndpointUrl
                    }
                />

                <OfficeActivityFeed
                    activities={officeProjection.activity ?? []}
                />
            </div>
        </>
    );
}

/**
 * Convert stable enum-like values into readable labels.
 */
function humanize(value: string) {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}
