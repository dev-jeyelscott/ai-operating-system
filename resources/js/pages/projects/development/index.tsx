import { Deferred, Head, Link, usePoll } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    Clock3,
    GitPullRequestArrow,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

export type DevelopmentQueueTicket = {
    id: string;
    title: string;
    objectiveSummary: string;
    priority: string;
    risk: string;
    roadmapPosition: number;
    criticalPath: { active: boolean; rank: number | null };
    status: string;
    desiredState: string;
    reportedState: string | null;
    observedState: string | null;
    actualState: string;
    dependencies: Array<{ id: string; title: string; status: string }>;
    approvalState: string;
    activeLease: boolean;
    leaseExpiresAt: string | null;
    executionState: string | null;
    attemptCount: number;
    retryLimit: number;
    nextAttemptAt: string | null;
    providerAvailable: boolean;
    budgetAvailable: boolean;
    ineligibilityReasonCodes: string[];
    inspectorUrl: string | null;
};

export type DevelopmentQueue = {
    metadata: {
        asOf: string;
        approvedRoadmapId: number | null;
        approvedRoadmapRevision: number | null;
        queueFingerprint: string | null;
        noWorkableTicket: boolean;
    };
    workable: DevelopmentQueueTicket[];
    ineligible: DevelopmentQueueTicket[];
    retryScheduled: DevelopmentQueueTicket[];
};

export type DevelopmentLease = {
    id: string;
    ticketId: string;
    executionId: string;
    owner: string;
    expiresAt: string;
    heartbeatAt: string;
    expired: boolean;
};

export type DevelopmentQueuePageProps = {
    organization: { id: number; name: string; slug: string };
    project: {
        id: number;
        name: string;
        slug: string;
        status: string;
        terminal: boolean;
    };
    projectUrl: string;
    queue?: DevelopmentQueue;
    leases?: DevelopmentLease[];
};

export default function DevelopmentQueuePage({
    organization,
    project,
    projectUrl,
    queue,
    leases,
}: DevelopmentQueuePageProps) {
    usePoll(
        5_000,
        { only: ['queue', 'leases'] },
        { autoStart: !project.terminal },
    );

    return (
        <>
            <Head title={`${project.name} development queue`} />
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
                                Development queue
                            </h1>
                            <Badge variant="outline">
                                {humanize(project.status)}
                            </Badge>
                        </div>
                        <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                            Authoritative workable and blocked tickets from the
                            latest approved roadmap for {project.name}.
                        </p>
                    </div>
                    <div className="rounded-lg border bg-card px-4 py-3 text-sm">
                        <p className="font-medium">{organization.name}</p>
                        <p className="text-muted-foreground">
                            {project.terminal
                                ? 'Polling stopped'
                                : 'Refreshes every 5 seconds'}
                        </p>
                    </div>
                </header>

                <Deferred
                    data={['queue', 'leases']}
                    fallback={<QueueSkeleton />}
                >
                    {queue ? (
                        <QueueContent queue={queue} leases={leases ?? []} />
                    ) : (
                        <DeferredRescue />
                    )}
                </Deferred>
            </main>
        </>
    );
}

export function QueueContent({
    queue,
    leases,
}: {
    queue: DevelopmentQueue;
    leases: DevelopmentLease[];
}) {
    const [currentTime, setCurrentTime] = useState(0);

    useEffect(() => {
        const updateCurrentTime = () => setCurrentTime(Date.now());
        updateCurrentTime();
        const interval = window.setInterval(updateCurrentTime, 5_000);

        return () => window.clearInterval(interval);
    }, []);

    const stale =
        currentTime > 0 &&
        currentTime - new Date(queue.metadata.asOf).getTime() > 30_000;

    return (
        <div className="flex flex-col gap-6">
            <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                <Clock3 className="size-4" aria-hidden="true" />
                <span>As of {formatDate(queue.metadata.asOf)}</span>
                {queue.metadata.approvedRoadmapRevision !== null && (
                    <Badge variant="secondary">
                        Roadmap revision{' '}
                        {queue.metadata.approvedRoadmapRevision}
                    </Badge>
                )}
                {stale && (
                    <Badge variant="destructive">Data may be stale</Badge>
                )}
            </div>

            {queue.metadata.approvedRoadmapId === null ? (
                <NeutralState
                    title="No approved development queue"
                    description="Approve a roadmap before development tickets can be evaluated."
                />
            ) : queue.metadata.noWorkableTicket ? (
                <NeutralState
                    title="No workable ticket right now"
                    description="The approved queue loaded successfully. Review the blocked reasons, active leases, or retry schedule below."
                />
            ) : null}

            <TicketSection
                id="workable-tickets"
                title="Workable queue"
                description="Eligible tickets in deterministic selection order."
                tickets={queue.workable}
                empty="No ticket currently passes every execution policy."
            />
            <TicketSection
                id="blocked-tickets"
                title="Blocked and ineligible tickets"
                description="Tickets remain in roadmap order and include every stable policy reason."
                tickets={queue.ineligible}
                empty="No blocked or ineligible tickets."
                showReasons
            />
            <LeaseSection leases={leases} />
            <TicketSection
                id="retry-scheduled-work"
                title="Retry-scheduled work"
                description="Development executions waiting for their next domain retry."
                tickets={queue.retryScheduled}
                empty="No development retry is scheduled."
            />
        </div>
    );
}

function TicketSection({
    id,
    title,
    description,
    tickets,
    empty,
    showReasons = false,
}: {
    id: string;
    title: string;
    description: string;
    tickets: DevelopmentQueueTicket[];
    empty: string;
    showReasons?: boolean;
}) {
    return (
        <section
            aria-labelledby={`${id}-heading`}
            className="flex flex-col gap-3"
        >
            <div>
                <h2 id={`${id}-heading`} className="text-lg font-semibold">
                    {title}
                </h2>
                <p className="text-sm text-muted-foreground">{description}</p>
            </div>
            {tickets.length === 0 ? (
                <Card>
                    <CardContent className="py-6 text-sm text-muted-foreground">
                        {empty}
                    </CardContent>
                </Card>
            ) : (
                <div className="grid gap-4 lg:grid-cols-2">
                    {tickets.map((ticket) => (
                        <TicketCard
                            key={ticket.id}
                            ticket={ticket}
                            showReasons={showReasons}
                        />
                    ))}
                </div>
            )}
        </section>
    );
}

function TicketCard({
    ticket,
    showReasons,
}: {
    ticket: DevelopmentQueueTicket;
    showReasons: boolean;
}) {
    return (
        <Card>
            <CardHeader className="gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <Badge variant="outline">{ticket.id}</Badge>
                    <Badge>{humanize(ticket.status)}</Badge>
                    <Badge variant={priorityVariant(ticket.priority)}>
                        {humanize(ticket.priority)}
                    </Badge>
                    <Badge variant={riskVariant(ticket.risk)}>
                        {humanize(ticket.risk)} risk
                    </Badge>
                    {ticket.activeLease && (
                        <Badge variant="secondary">Active lease</Badge>
                    )}
                </div>
                <CardTitle className="text-base">{ticket.title}</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-4 text-sm">
                <p className="text-muted-foreground">
                    {ticket.objectiveSummary}
                </p>
                <dl className="grid grid-cols-2 gap-x-4 gap-y-2">
                    <Detail
                        label="Position"
                        value={`#${ticket.roadmapPosition}`}
                    />
                    <Detail
                        label="Approval"
                        value={humanize(ticket.approvalState)}
                    />
                    <Detail
                        label="Execution"
                        value={humanize(ticket.executionState ?? 'not_started')}
                    />
                    <Detail
                        label="Attempts"
                        value={`${ticket.attemptCount} of ${ticket.retryLimit + 1}`}
                    />
                </dl>
                {ticket.dependencies.length > 0 && (
                    <div>
                        <h3 className="font-medium">Dependencies</h3>
                        <ul className="mt-1 flex flex-col gap-1 text-muted-foreground">
                            {ticket.dependencies.map((dependency) => (
                                <li key={dependency.id}>
                                    {dependency.id}: {dependency.title} (
                                    {humanize(dependency.status)})
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
                {showReasons && ticket.ineligibilityReasonCodes.length > 0 && (
                    <div>
                        <h3 className="font-medium">
                            Why this ticket cannot run
                        </h3>
                        <ul className="mt-2 flex flex-wrap gap-2">
                            {ticket.ineligibilityReasonCodes.map((reason) => (
                                <li key={reason}>
                                    <Badge variant="destructive">
                                        {reasonSummary(reason)}
                                    </Badge>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
                {ticket.nextAttemptAt && (
                    <p className="text-muted-foreground">
                        Next attempt {formatDate(ticket.nextAttemptAt)}
                    </p>
                )}
                {ticket.inspectorUrl && (
                    <Button asChild variant="outline" className="self-start">
                        <Link href={ticket.inspectorUrl}>
                            <GitPullRequestArrow aria-hidden="true" />
                            Inspect execution
                        </Link>
                    </Button>
                )}
            </CardContent>
        </Card>
    );
}

function LeaseSection({ leases }: { leases: DevelopmentLease[] }) {
    return (
        <section
            aria-labelledby="active-leases-heading"
            className="flex flex-col gap-3"
        >
            <div>
                <h2
                    id="active-leases-heading"
                    className="text-lg font-semibold"
                >
                    Active leases
                </h2>
                <p className="text-sm text-muted-foreground">
                    Durable PostgreSQL ticket ownership currently excluding
                    queue candidates.
                </p>
            </div>
            {leases.length === 0 ? (
                <Card>
                    <CardContent className="py-6 text-sm text-muted-foreground">
                        No ticket has an active lease.
                    </CardContent>
                </Card>
            ) : (
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {leases.map((lease) => (
                        <Card key={lease.id}>
                            <CardContent className="flex flex-col gap-2 py-5 text-sm">
                                <div className="flex items-center justify-between gap-2">
                                    <span className="font-medium">
                                        {lease.ticketId}
                                    </span>
                                    <Badge
                                        variant={
                                            lease.expired
                                                ? 'destructive'
                                                : 'secondary'
                                        }
                                    >
                                        {lease.expired
                                            ? 'Expired, recovery pending'
                                            : 'Active'}
                                    </Badge>
                                </div>
                                <p className="text-muted-foreground">
                                    Owner {lease.owner}
                                </p>
                                <p className="text-muted-foreground">
                                    Expires {formatDate(lease.expiresAt)}
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            )}
        </section>
    );
}

function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="font-medium">{value}</dd>
        </div>
    );
}

function NeutralState({
    title,
    description,
}: {
    title: string;
    description: string;
}) {
    return (
        <Alert>
            <ShieldCheck aria-hidden="true" />
            <AlertTitle>{title}</AlertTitle>
            <AlertDescription>{description}</AlertDescription>
        </Alert>
    );
}

function DeferredRescue() {
    return (
        <Alert variant="destructive">
            <AlertTriangle aria-hidden="true" />
            <AlertTitle>Queue data did not load</AlertTitle>
            <AlertDescription>
                The project page is still available. Refresh to request the
                authorized queue again.
            </AlertDescription>
        </Alert>
    );
}

function QueueSkeleton() {
    return (
        <section
            aria-label="Loading development queue"
            aria-busy="true"
            className="grid gap-4 md:grid-cols-2"
        >
            {[0, 1, 2, 3].map((item) => (
                <Card key={item}>
                    <CardContent className="flex flex-col gap-3 py-6">
                        <Skeleton className="h-5 w-28" />
                        <Skeleton className="h-6 w-3/4" />
                        <Skeleton className="h-4 w-full" />
                    </CardContent>
                </Card>
            ))}
            <span className="sr-only">Loading development queue</span>
        </section>
    );
}

function priorityVariant(
    priority: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    return priority === 'critical'
        ? 'destructive'
        : priority === 'high'
          ? 'default'
          : 'secondary';
}

function riskVariant(
    risk: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    return ['critical', 'high'].includes(risk) ? 'destructive' : 'outline';
}

function reasonSummary(code: string): string {
    const reasons: Record<string, string> = {
        status_not_eligible: 'Status is not executable',
        changes_requested_not_approved: 'Changes are not approved',
        dependency_incomplete: 'A dependency is incomplete',
        blocker_unresolved: 'A blocker is unresolved',
        approval_missing: 'Approval is required',
        project_not_active: 'Project is not active',
        provider_unavailable: 'Simulation provider is unavailable',
        budget_unavailable: 'Execution budget is unavailable',
        retry_policy_exhausted: 'Retry policy is exhausted',
        active_lease: 'Another execution owns this ticket',
    };

    return reasons[code] ?? humanize(code);
}

function humanize(value: string): string {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}
