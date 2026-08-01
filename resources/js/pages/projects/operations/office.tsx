import { Head, Link, router, usePoll } from '@inertiajs/react';
import { ArrowLeft, LayoutDashboard, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { OfficeShell } from '@/features/office/components/office-shell';
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
 * Render the tenant-scoped office page without coupling workflow operations to
 * WebGL availability.
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

    usePoll(10_000, {
        only: ['officeProjection'],
        onStart: () => setRefreshing(true),
        onFinish: () => setRefreshing(false),
    });

    /**
     * Refresh only the persisted projection prop.
     */
    function refreshProjection() {
        router.reload({
            only: ['officeProjection'],
            onStart: () => setRefreshing(true),
            onFinish: () => setRefreshing(false),
        });
    }

    return (
        <>
            <Head title={`${project.name} office`} />

            <main className="flex flex-1 flex-col gap-6 p-4 md:p-6">
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
                        </div>

                        <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                            Projection-driven visualization for {project.name}{' '}
                            in {organization.name}. The accessible dashboard
                            remains the authoritative control surface.
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
                                className={refreshing ? 'animate-spin' : ''}
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
                        : `Projection endpoint: ${officeProjectionEndpointUrl}`}
                </div>

                <OfficeShell
                    projection={officeProjection}
                    operationsUrl={operationsUrl}
                    telemetryEndpointUrl={officeTelemetryEndpointUrl}
                />
            </main>
        </>
    );
}

/**
 * Convert stable enum-style values into readable labels.
 */
function humanize(value: string) {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}
