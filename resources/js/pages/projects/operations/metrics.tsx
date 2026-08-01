import { Head, Link, usePoll } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    Clock3,
    DatabaseZap,
    Gauge,
    RefreshCw,
    RotateCcw,
    Workflow,
} from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type OperationalMetrics = {
    metadata: {
        schemaVersion: number;
        asOf: string;
        windowStartedAt: string;
        windowDays: number;
    };
    queue: {
        currentlyQueued: number;
        longWaiting: number;
        longWaitThresholdSeconds: number;
        oldestQueuedAt: string | null;
        oldestQueueAgeSeconds: number | null;
        averageWaitSeconds: number | null;
        p95WaitSeconds: number | null;
    };
    executions: {
        total: number;
        active: number;
        completed: number;
        failed: number;
        retryScheduled: number;
        completionRate: number;
        failureRate: number;
        averageDurationSeconds: number | null;
        p95DurationSeconds: number | null;
    };
    attempts: {
        total: number;
        retries: number;
        failed: number;
        timedOut: number;
        retryRate: number;
    };
    leases: {
        total: number;
        active: number;
        expiredActive: number;
        staleHeartbeat: number;
        released: number;
        manualRecoveryReleases: number;
        staleAfterSeconds: number;
    };
    workflows: {
        total: number;
        active: number;
        blocked: number;
        completed: number;
        completionRate: number;
        averageDurationSeconds: number | null;
        p95DurationSeconds: number | null;
    };
    deadLetters: {
        current: number;
        replayed: number;
        dispatchAttempts: number;
        oldestDeadLetteredAt: string | null;
    };
};

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
    dashboardUrl: string;
    recoveryCenterUrl: string;
    metrics: OperationalMetrics;
};

/**
 * Render tenant-scoped queue, execution, lease, workflow, and recovery metrics.
 */
export default function ProjectOperationalMetrics({
    organization,
    project,
    dashboardUrl,
    recoveryCenterUrl,
    metrics,
}: Props) {
    const [refreshing, setRefreshing] = useState(false);

    usePoll(15_000, {
        only: ['metrics'],
        onStart: () => setRefreshing(true),
        onFinish: () => setRefreshing(false),
    });

    return (
        <>
            <Head title={`${project.name} operational metrics`} />

            <main className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <Button asChild variant="ghost" size="sm">
                            <Link href={dashboardUrl}>
                                <ArrowLeft aria-hidden="true" />
                                Back to operational dashboard
                            </Link>
                        </Button>

                        <div className="mt-4 flex flex-wrap items-center gap-3">
                            <h1 className="text-2xl font-semibold tracking-tight">
                                Operational metrics
                            </h1>

                            <Badge variant="outline">
                                {metrics.metadata.windowDays}-day window
                            </Badge>
                        </div>

                        <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                            Queue, execution, retry, lease, workflow, and
                            dead-letter health for {project.name}. Values are
                            derived from authoritative application records.
                        </p>
                    </div>

                    <Button asChild variant="outline">
                        <Link href={recoveryCenterUrl}>
                            <RotateCcw aria-hidden="true" />
                            Recovery center
                        </Link>
                    </Button>
                </header>

                <div
                    role="status"
                    aria-live="polite"
                    className="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <RefreshCw
                        aria-hidden="true"
                        className={
                            refreshing ? 'size-4 animate-spin' : 'size-4'
                        }
                    />
                    {refreshing
                        ? 'Refreshing metrics.'
                        : `Updated ${formatDate(metrics.metadata.asOf)}.`}
                </div>

                <section
                    aria-labelledby="queue-metrics-heading"
                    className="space-y-4"
                >
                    <SectionHeading
                        id="queue-metrics-heading"
                        title="Queue health"
                        icon={Clock3}
                    />

                    <MetricGrid>
                        <MetricCard
                            label="Currently queued"
                            value={metrics.queue.currentlyQueued}
                        />
                        <MetricCard
                            label="Long waiting"
                            value={metrics.queue.longWaiting}
                            warning={metrics.queue.longWaiting > 0}
                        />
                        <MetricCard
                            label="Average wait"
                            value={formatDuration(
                                metrics.queue.averageWaitSeconds,
                            )}
                        />
                        <MetricCard
                            label="P95 wait"
                            value={formatDuration(metrics.queue.p95WaitSeconds)}
                        />
                        <MetricCard
                            label="Oldest queue age"
                            value={formatDuration(
                                metrics.queue.oldestQueueAgeSeconds,
                            )}
                        />
                        <MetricCard
                            label="Long-wait threshold"
                            value={formatDuration(
                                metrics.queue.longWaitThresholdSeconds,
                            )}
                        />
                    </MetricGrid>
                </section>

                <section
                    aria-labelledby="execution-metrics-heading"
                    className="space-y-4"
                >
                    <SectionHeading
                        id="execution-metrics-heading"
                        title="Execution health"
                        icon={Activity}
                    />

                    <MetricGrid>
                        <MetricCard
                            label="Executions"
                            value={metrics.executions.total}
                        />
                        <MetricCard
                            label="Active"
                            value={metrics.executions.active}
                        />
                        <MetricCard
                            label="Completed"
                            value={metrics.executions.completed}
                        />
                        <MetricCard
                            label="Failed"
                            value={metrics.executions.failed}
                            warning={metrics.executions.failed > 0}
                        />
                        <MetricCard
                            label="Completion rate"
                            value={formatPercent(
                                metrics.executions.completionRate,
                            )}
                        />
                        <MetricCard
                            label="Failure rate"
                            value={formatPercent(
                                metrics.executions.failureRate,
                            )}
                            warning={metrics.executions.failureRate > 0}
                        />
                        <MetricCard
                            label="Average duration"
                            value={formatDuration(
                                metrics.executions.averageDurationSeconds,
                            )}
                        />
                        <MetricCard
                            label="P95 duration"
                            value={formatDuration(
                                metrics.executions.p95DurationSeconds,
                            )}
                        />
                    </MetricGrid>
                </section>

                <div className="grid gap-6 xl:grid-cols-2">
                    <section
                        aria-labelledby="attempt-metrics-heading"
                        className="space-y-4"
                    >
                        <SectionHeading
                            id="attempt-metrics-heading"
                            title="Attempts and retries"
                            icon={RotateCcw}
                        />

                        <MetricGrid columns="two">
                            <MetricCard
                                label="Attempts"
                                value={metrics.attempts.total}
                            />
                            <MetricCard
                                label="Retries"
                                value={metrics.attempts.retries}
                            />
                            <MetricCard
                                label="Retry rate"
                                value={formatPercent(
                                    metrics.attempts.retryRate,
                                )}
                            />
                            <MetricCard
                                label="Failed attempts"
                                value={metrics.attempts.failed}
                                warning={metrics.attempts.failed > 0}
                            />
                            <MetricCard
                                label="Timed out"
                                value={metrics.attempts.timedOut}
                                warning={metrics.attempts.timedOut > 0}
                            />
                            <MetricCard
                                label="Retry scheduled"
                                value={metrics.executions.retryScheduled}
                            />
                        </MetricGrid>
                    </section>

                    <section
                        aria-labelledby="lease-metrics-heading"
                        className="space-y-4"
                    >
                        <SectionHeading
                            id="lease-metrics-heading"
                            title="Execution leases"
                            icon={DatabaseZap}
                        />

                        <MetricGrid columns="two">
                            <MetricCard
                                label="Active"
                                value={metrics.leases.active}
                            />
                            <MetricCard
                                label="Released"
                                value={metrics.leases.released}
                            />
                            <MetricCard
                                label="Expired active"
                                value={metrics.leases.expiredActive}
                                warning={metrics.leases.expiredActive > 0}
                            />
                            <MetricCard
                                label="Stale heartbeat"
                                value={metrics.leases.staleHeartbeat}
                                warning={metrics.leases.staleHeartbeat > 0}
                            />
                            <MetricCard
                                label="Manual recoveries"
                                value={metrics.leases.manualRecoveryReleases}
                            />
                            <MetricCard
                                label="Stale threshold"
                                value={formatDuration(
                                    metrics.leases.staleAfterSeconds,
                                )}
                            />
                        </MetricGrid>
                    </section>
                </div>

                <div className="grid gap-6 xl:grid-cols-2">
                    <section
                        aria-labelledby="workflow-metrics-heading"
                        className="space-y-4"
                    >
                        <SectionHeading
                            id="workflow-metrics-heading"
                            title="Workflow health"
                            icon={Workflow}
                        />

                        <MetricGrid columns="two">
                            <MetricCard
                                label="Active"
                                value={metrics.workflows.active}
                            />
                            <MetricCard
                                label="Completed"
                                value={metrics.workflows.completed}
                            />
                            <MetricCard
                                label="Blocked"
                                value={metrics.workflows.blocked}
                                warning={metrics.workflows.blocked > 0}
                            />
                            <MetricCard
                                label="Completion rate"
                                value={formatPercent(
                                    metrics.workflows.completionRate,
                                )}
                            />
                            <MetricCard
                                label="Average duration"
                                value={formatDuration(
                                    metrics.workflows.averageDurationSeconds,
                                )}
                            />
                            <MetricCard
                                label="P95 duration"
                                value={formatDuration(
                                    metrics.workflows.p95DurationSeconds,
                                )}
                            />
                        </MetricGrid>
                    </section>

                    <section
                        aria-labelledby="dead-letter-metrics-heading"
                        className="space-y-4"
                    >
                        <SectionHeading
                            id="dead-letter-metrics-heading"
                            title="Dead letters"
                            icon={AlertTriangle}
                        />

                        <MetricGrid columns="two">
                            <MetricCard
                                label="Current"
                                value={metrics.deadLetters.current}
                                warning={metrics.deadLetters.current > 0}
                            />
                            <MetricCard
                                label="Replayed"
                                value={metrics.deadLetters.replayed}
                            />
                            <MetricCard
                                label="Dispatch attempts"
                                value={metrics.deadLetters.dispatchAttempts}
                            />
                            <MetricCard
                                label="Oldest dead letter"
                                value={
                                    metrics.deadLetters.oldestDeadLetteredAt
                                        ? formatDate(
                                              metrics.deadLetters
                                                  .oldestDeadLetteredAt,
                                          )
                                        : 'None'
                                }
                            />
                        </MetricGrid>
                    </section>
                </div>

                <footer className="text-xs text-muted-foreground">
                    Organization: {organization.name}. Schema version{' '}
                    {metrics.metadata.schemaVersion}. Window started{' '}
                    {formatDate(metrics.metadata.windowStartedAt)}.
                </footer>
            </main>
        </>
    );
}

/**
 * Render a section heading with a decorative icon.
 */
function SectionHeading({
    id,
    title,
    icon: Icon,
}: {
    id: string;
    title: string;
    icon: typeof Gauge;
}) {
    return (
        <div className="flex items-center gap-2">
            <Icon className="size-5 text-muted-foreground" aria-hidden="true" />
            <h2 id={id} className="text-lg font-semibold">
                {title}
            </h2>
        </div>
    );
}

/**
 * Render a responsive metric-card grid.
 */
function MetricGrid({
    children,
    columns = 'four',
}: {
    children: React.ReactNode;
    columns?: 'two' | 'four';
}) {
    return (
        <div
            className={
                columns === 'two'
                    ? 'grid gap-4 sm:grid-cols-2'
                    : 'grid gap-4 sm:grid-cols-2 xl:grid-cols-4'
            }
        >
            {children}
        </div>
    );
}

/**
 * Render one operational metric with optional warning semantics.
 */
function MetricCard({
    label,
    value,
    warning = false,
}: {
    label: string;
    value: string | number;
    warning?: boolean;
}) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="flex items-center justify-between gap-2 text-sm font-medium">
                    {label}
                    {warning ? (
                        <AlertTriangle
                            className="size-4"
                            aria-label="Requires attention"
                        />
                    ) : (
                        <CheckCircle2
                            className="size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                    )}
                </CardTitle>
            </CardHeader>
            <CardContent>
                <p className="text-2xl font-semibold tabular-nums">{value}</p>
            </CardContent>
        </Card>
    );
}

/**
 * Format an optional duration for human-readable display.
 */
function formatDuration(seconds: number | null): string {
    if (seconds === null) {
        return 'No data';
    }

    if (seconds < 60) {
        return `${seconds.toFixed(seconds < 10 ? 1 : 0)}s`;
    }

    if (seconds < 3600) {
        return `${(seconds / 60).toFixed(1)}m`;
    }

    return `${(seconds / 3600).toFixed(1)}h`;
}

/**
 * Format a decimal ratio as a percentage.
 */
function formatPercent(value: number): string {
    return new Intl.NumberFormat(undefined, {
        style: 'percent',
        maximumFractionDigits: 1,
    }).format(value);
}

/**
 * Format an ISO timestamp using the user's browser locale.
 */
function formatDate(value: string): string {
    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}
