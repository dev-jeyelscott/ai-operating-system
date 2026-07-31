import { Head, Link, router, usePoll } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    Clock3,
    Inbox,
    RefreshCw,
    RotateCcw,
    ShieldAlert,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline';

type OperationsAgent = {
    id: string;
    role: string;
    layer: string;
    capability: string;
    state: string;
    active: boolean;
    provider: string | null;
    requestedReasoning: string;
    effectiveReasoning: string | null;
    ticketId: string | null;
    attemptCount: number;
    retryLimit: number;
    nextAttemptAt: string | null;
    startedAt: string | null;
    finishedAt: string | null;
    contextUrl: string;
};

type OperationsTicket = {
    id: string;
    databaseId: number;
    title: string;
    logicalAgent: string | null;
    status: string;
    desiredState: string;
    reportedState: string | null;
    observedState: string | null;
    actualState: string;
    priority: string;
    risk: string;
    position: number;
    blocked: boolean;
    activeLease: boolean;
    leaseExpiresAt: string | null;
    contextUrl: string;
};

type OperationsContextItem = {
    type: string;
    id: string;
    title: string;
    message: string;
    contextUrl: string;
};

type OperationsApproval = {
    id: string;
    type: string;
    status: string;
    executionId: string | null;
    requestedAt: string;
    expiresAt: string | null;
    contextUrl: string;
};

type OperationsRetry = {
    executionId: string;
    capability: string;
    logicalRole: string | null;
    attemptCount: number;
    retryLimit: number;
    nextAttemptAt: string | null;
    contextUrl: string;
};

type OperationsDecision = {
    id: string;
    assessmentId: string;
    ticketId: string | null;
    action: string;
    reason: string | null;
    simulated: boolean;
    actualState: string;
    decidedAt: string;
    contextUrl: string;
};

export type OperationsData = {
    metadata: {
        schemaVersion: number;
        asOf: string;
        fingerprint: string;
    };
    project: {
        id: number;
        name: string;
        slug: string;
        status: string;
        archived: boolean;
    };
    workflow: {
        id: string;
        state: string;
        transitionSequence: number;
        completedAt: string | null;
    } | null;
    roadmap: {
        id: number;
        revision: number;
        status: string;
        readiness: string;
        approvedAt: string | null;
    } | null;
    summary: {
        activeAgents: number;
        ticketsTotal: number;
        ticketsByStatus: Record<string, number>;
        blockers: number;
        pendingApprovals: number;
        retriesScheduled: number;
        recentDecisions: number;
    };
    layers: Array<{
        key: string;
        label: string;
        state: string;
        activeAgents: number;
        agents: string[];
    }>;
    agents: OperationsAgent[];
    tickets: OperationsTicket[];
    blockers: OperationsContextItem[];
    approvals: OperationsApproval[];
    retries: OperationsRetry[];
    decisions: OperationsDecision[];
};

type Props = {
    organization: { id: number; name: string; slug: string };
    project: {
        id: number;
        name: string;
        slug: string;
        status: string;
        terminal: boolean;
    };
    projectUrl: string;
    approvalInboxUrl: string;
    operations: OperationsData;
};

/**
 * Render the complete non-3D operational interface for one project.
 */
export default function ProjectOperationsDashboard({
    organization,
    project,
    projectUrl,
    approvalInboxUrl,
    operations,
}: Props) {
    const [refreshing, setRefreshing] = useState(false);

    usePoll(10_000, {
        only: ['operations'],
        onStart: () => setRefreshing(true),
        onFinish: () => setRefreshing(false),
    });

    /**
     * Refresh only the authoritative operations prop on demand.
     */
    function refreshOperations() {
        router.reload({
            only: ['operations'],
            onStart: () => setRefreshing(true),
            onFinish: () => setRefreshing(false),
        });
    }

    return (
        <>
            <Head title={`${project.name} operations`} />

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
                                Operational dashboard
                            </h1>
                            <Badge variant="outline">
                                {humanize(project.status)}
                            </Badge>
                        </div>

                        <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                            Authoritative workflow, agent, ticket, blocker,
                            retry, approval, and decision state for{' '}
                            {project.name}. No WebGL or 3D interaction is
                            required.
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <Link href={approvalInboxUrl}>
                                <Inbox aria-hidden="true" />
                                Approval inbox
                            </Link>
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={refreshOperations}
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
                        ? 'Refreshing operational state.'
                        : `Operational state updated ${formatDate(operations.metadata.asOf)}.`}
                </div>

                <OperationsDashboardContent
                    organizationName={organization.name}
                    operations={operations}
                    approvalInboxUrl={approvalInboxUrl}
                />
            </main>
        </>
    );
}

/**
 * Render the dashboard body independently for component and accessibility tests.
 */
export function OperationsDashboardContent({
    organizationName,
    operations,
    approvalInboxUrl,
}: {
    organizationName: string;
    operations: OperationsData;
    approvalInboxUrl: string;
}) {
    const hasSimulation =
        operations.agents.some((agent) => agent.provider === 'simulation') ||
        operations.decisions.some((decision) => decision.simulated);

    return (
        <div className="space-y-6">
            {hasSimulation && (
                <Alert>
                    <ShieldAlert aria-hidden="true" />
                    <AlertTitle>Simulation remains unverified</AlertTitle>
                    <AlertDescription>
                        Simulated agents, artifacts, QA, and merge decisions are
                        advisory. They cannot authorize a real repository merge
                        or be displayed as verified execution evidence.
                    </AlertDescription>
                </Alert>
            )}

            <SummaryCards operations={operations} />
            <LayerOverview operations={operations} />

            <div className="grid gap-6 xl:grid-cols-2">
                <BlockerList blockers={operations.blockers} />
                <ApprovalList
                    approvals={operations.approvals}
                    approvalInboxUrl={approvalInboxUrl}
                />
            </div>

            <AgentTable agents={operations.agents} />
            <TicketTable tickets={operations.tickets} />

            <div className="grid gap-6 xl:grid-cols-2">
                <RetryList retries={operations.retries} />
                <DecisionList decisions={operations.decisions} />
            </div>

            <p className="text-xs text-muted-foreground">
                Organization: {organizationName}. Read-model schema version{' '}
                {operations.metadata.schemaVersion}. Fingerprint{' '}
                {operations.metadata.fingerprint.slice(0, 12)}…
            </p>
        </div>
    );
}

/**
 * Render high-level operational counters.
 */
function SummaryCards({ operations }: { operations: OperationsData }) {
    const cards = [
        {
            label: 'Active agents',
            value: operations.summary.activeAgents,
            icon: Users,
        },
        {
            label: 'Tickets',
            value: operations.summary.ticketsTotal,
            icon: Activity,
        },
        {
            label: 'Blockers',
            value: operations.summary.blockers,
            icon: AlertTriangle,
        },
        {
            label: 'Pending approvals',
            value: operations.summary.pendingApprovals,
            icon: Inbox,
        },
        {
            label: 'Scheduled retries',
            value: operations.summary.retriesScheduled,
            icon: RotateCcw,
        },
        {
            label: 'Recent decisions',
            value: operations.summary.recentDecisions,
            icon: CheckCircle2,
        },
    ];

    return (
        <section aria-labelledby="operations-summary-heading">
            <h2 id="operations-summary-heading" className="sr-only">
                Operations summary
            </h2>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
                {cards.map((card) => (
                    <Card key={card.label}>
                        <CardHeader className="flex flex-row items-center justify-between gap-3 pb-2">
                            <CardTitle className="text-sm font-medium">
                                {card.label}
                            </CardTitle>
                            <card.icon
                                className="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                        </CardHeader>
                        <CardContent>
                            <p className="text-3xl font-semibold tabular-nums">
                                {card.value}
                            </p>
                        </CardContent>
                    </Card>
                ))}
            </div>

            <div
                className="mt-4 flex flex-wrap gap-2"
                aria-label="Ticket status totals"
            >
                {Object.entries(operations.summary.ticketsByStatus).map(
                    ([status, count]) => (
                        <Badge key={status} variant="outline">
                            {humanize(status)}: {count}
                        </Badge>
                    ),
                )}
            </div>
        </section>
    );
}

/**
 * Render the four stable workflow layers from backend state.
 */
function LayerOverview({ operations }: { operations: OperationsData }) {
    return (
        <section aria-labelledby="workflow-layers-heading">
            <div className="mb-3 flex items-center justify-between gap-3">
                <h2
                    id="workflow-layers-heading"
                    className="text-lg font-semibold"
                >
                    Workflow layers
                </h2>
                <Badge variant="outline">
                    {operations.workflow
                        ? humanize(operations.workflow.state)
                        : 'No workflow'}
                </Badge>
            </div>

            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                {operations.layers.map((layer) => (
                    <Card key={layer.key}>
                        <CardHeader>
                            <div className="flex items-center justify-between gap-3">
                                <CardTitle className="text-base">
                                    {layer.label}
                                </CardTitle>
                                <StatusBadge value={layer.state} />
                            </div>
                        </CardHeader>
                        <CardContent className="text-sm text-muted-foreground">
                            {layer.activeAgents} active agent
                            {layer.activeAgents === 1 ? '' : 's'} ·{' '}
                            {layer.agents.length} recent execution
                            {layer.agents.length === 1 ? '' : 's'}
                        </CardContent>
                    </Card>
                ))}
            </div>
        </section>
    );
}

/**
 * Render current blockers with direct remediation links.
 */
function BlockerList({ blockers }: { blockers: OperationsContextItem[] }) {
    return (
        <section aria-labelledby="blockers-heading">
            <Card className="h-full">
                <CardHeader>
                    <CardTitle id="blockers-heading">Blockers</CardTitle>
                </CardHeader>
                <CardContent>
                    {blockers.length === 0 ? (
                        <EmptyState message="No active blockers." />
                    ) : (
                        <ul className="space-y-3">
                            {blockers.map((blocker) => (
                                <li
                                    key={`${blocker.type}-${blocker.id}`}
                                    className="rounded-lg border p-4"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <p className="font-medium">
                                                {blocker.title}
                                            </p>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                {blocker.message}
                                            </p>
                                        </div>
                                        <Button
                                            asChild
                                            size="sm"
                                            variant="outline"
                                        >
                                            <Link href={blocker.contextUrl}>
                                                Review blocker
                                            </Link>
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>
        </section>
    );
}

/**
 * Render pending approvals and route them through the consolidated inbox.
 */
function ApprovalList({
    approvals,
    approvalInboxUrl,
}: {
    approvals: OperationsApproval[];
    approvalInboxUrl: string;
}) {
    return (
        <section aria-labelledby="pending-approvals-heading">
            <Card className="h-full">
                <CardHeader>
                    <CardTitle id="pending-approvals-heading">
                        Pending approvals
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    {approvals.length === 0 ? (
                        <EmptyState message="No pending approvals." />
                    ) : (
                        <ul className="space-y-3">
                            {approvals.map((approval) => (
                                <li
                                    key={approval.id}
                                    className="rounded-lg border p-4"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <p className="font-medium">
                                                {humanize(approval.type)}
                                            </p>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                Requested{' '}
                                                {formatDate(
                                                    approval.requestedAt,
                                                )}
                                                {approval.expiresAt
                                                    ? ` · expires ${formatDate(approval.expiresAt)}`
                                                    : ''}
                                            </p>
                                        </div>
                                        <Button asChild size="sm">
                                            <Link
                                                href={`${approvalInboxUrl}?approval=${encodeURIComponent(approval.id)}#approval-${approval.id}`}
                                            >
                                                Open inbox item
                                            </Link>
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>
        </section>
    );
}

/**
 * Render logical agents in a semantic and keyboard-navigable table.
 */
function AgentTable({ agents }: { agents: OperationsAgent[] }) {
    return (
        <section aria-labelledby="agents-heading">
            <Card>
                <CardHeader>
                    <CardTitle id="agents-heading">Logical agents</CardTitle>
                </CardHeader>
                <CardContent>
                    {agents.length === 0 ? (
                        <EmptyState message="No active or recent logical agents." />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[56rem] border-collapse text-sm">
                                <caption className="sr-only">
                                    Logical agents, workflow layers, providers,
                                    tickets, reasoning, and execution state
                                </caption>
                                <thead>
                                    <tr className="border-b text-left">
                                        <th
                                            scope="col"
                                            className="px-3 py-3 font-medium"
                                        >
                                            Role
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-3 font-medium"
                                        >
                                            Layer
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-3 font-medium"
                                        >
                                            State
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-3 font-medium"
                                        >
                                            Provider
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-3 font-medium"
                                        >
                                            Ticket
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-3 font-medium"
                                        >
                                            Reasoning
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-3 font-medium"
                                        >
                                            Attempts
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-3 font-medium"
                                        >
                                            Action
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {agents.map((agent) => (
                                        <tr
                                            key={agent.id}
                                            className="border-b align-top last:border-0"
                                        >
                                            <th
                                                scope="row"
                                                className="px-3 py-3 text-left font-medium"
                                            >
                                                {agent.role}
                                            </th>
                                            <td className="px-3 py-3">
                                                {humanize(agent.layer)}
                                            </td>
                                            <td className="px-3 py-3">
                                                <StatusBadge
                                                    value={agent.state}
                                                />
                                            </td>
                                            <td className="px-3 py-3">
                                                <div className="flex flex-wrap gap-2">
                                                    <span>
                                                        {agent.provider ??
                                                            'Not assigned'}
                                                    </span>
                                                    {agent.provider ===
                                                        'simulation' && (
                                                        <Badge variant="secondary">
                                                            Simulated
                                                        </Badge>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-3 py-3">
                                                {agent.ticketId ?? '—'}
                                            </td>
                                            <td className="px-3 py-3">
                                                {humanize(
                                                    agent.effectiveReasoning ??
                                                        agent.requestedReasoning,
                                                )}
                                            </td>
                                            <td className="px-3 py-3 tabular-nums">
                                                {agent.attemptCount}/
                                                {agent.retryLimit + 1}
                                            </td>
                                            <td className="px-3 py-3">
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="outline"
                                                >
                                                    <Link
                                                        href={agent.contextUrl}
                                                    >
                                                        Inspect
                                                    </Link>
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </CardContent>
            </Card>
        </section>
    );
}

/**
 * Render authoritative ticket state in roadmap order.
 */
function TicketTable({ tickets }: { tickets: OperationsTicket[] }) {
    return (
        <section aria-labelledby="tickets-heading">
            <Card>
                <CardHeader>
                    <CardTitle id="tickets-heading">Tickets</CardTitle>
                </CardHeader>
                <CardContent>
                    {tickets.length === 0 ? (
                        <EmptyState message="No approved roadmap tickets are available." />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[64rem] border-collapse text-sm">
                                <caption className="sr-only">
                                    Authoritative ticket status, desired state,
                                    actual state, risk, priority, and lease
                                    state
                                </caption>
                                <thead>
                                    <tr className="border-b text-left">
                                        <th
                                            scope="col"
                                            className="px-3 py-3 font-medium"
                                        >
                                            Ticket
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-3 font-medium"
                                        >
                                            Status
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-3 font-medium"
                                        >
                                            Desired
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-3 font-medium"
                                        >
                                            Actual
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-3 font-medium"
                                        >
                                            Risk
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-3 font-medium"
                                        >
                                            Priority
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-3 font-medium"
                                        >
                                            Lease
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-3 font-medium"
                                        >
                                            Action
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {tickets.map((ticket) => (
                                        <tr
                                            key={ticket.id}
                                            className="border-b align-top last:border-0"
                                        >
                                            <th
                                                scope="row"
                                                className="px-3 py-3 text-left"
                                            >
                                                <span className="font-medium">
                                                    {ticket.id}
                                                </span>
                                                <span className="mt-1 block max-w-md text-muted-foreground">
                                                    {ticket.title}
                                                </span>
                                            </th>
                                            <td className="px-3 py-3">
                                                <StatusBadge
                                                    value={ticket.status}
                                                />
                                            </td>
                                            <td className="px-3 py-3">
                                                {humanize(ticket.desiredState)}
                                            </td>
                                            <td className="px-3 py-3">
                                                <div className="flex flex-wrap gap-2">
                                                    <StatusBadge
                                                        value={
                                                            ticket.actualState
                                                        }
                                                    />
                                                </div>
                                            </td>
                                            <td className="px-3 py-3">
                                                {humanize(ticket.risk)}
                                            </td>
                                            <td className="px-3 py-3">
                                                {humanize(ticket.priority)}
                                            </td>
                                            <td className="px-3 py-3">
                                                {ticket.activeLease
                                                    ? `Active${ticket.leaseExpiresAt ? ` until ${formatDate(ticket.leaseExpiresAt)}` : ''}`
                                                    : 'None'}
                                            </td>
                                            <td className="px-3 py-3">
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="outline"
                                                >
                                                    <Link
                                                        href={ticket.contextUrl}
                                                    >
                                                        Open ticket
                                                    </Link>
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </CardContent>
            </Card>
        </section>
    );
}

/**
 * Render scheduled execution retries.
 */
function RetryList({ retries }: { retries: OperationsRetry[] }) {
    return (
        <section aria-labelledby="retries-heading">
            <Card className="h-full">
                <CardHeader>
                    <CardTitle id="retries-heading">
                        Scheduled retries
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    {retries.length === 0 ? (
                        <EmptyState message="No retries are scheduled." />
                    ) : (
                        <ul className="space-y-3">
                            {retries.map((retry) => (
                                <li
                                    key={retry.executionId}
                                    className="rounded-lg border p-4"
                                >
                                    <p className="font-medium">
                                        {retry.logicalRole ??
                                            humanize(retry.capability)}
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Attempt {retry.attemptCount} of{' '}
                                        {retry.retryLimit + 1}
                                        {retry.nextAttemptAt
                                            ? ` · next ${formatDate(retry.nextAttemptAt)}`
                                            : ''}
                                    </p>
                                    <Button
                                        asChild
                                        size="sm"
                                        variant="outline"
                                        className="mt-3"
                                    >
                                        <Link href={retry.contextUrl}>
                                            Inspect retry
                                        </Link>
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>
        </section>
    );
}

/**
 * Render recent human merge dispositions and preserve simulation labels.
 */
function DecisionList({ decisions }: { decisions: OperationsDecision[] }) {
    return (
        <section aria-labelledby="decisions-heading">
            <Card className="h-full">
                <CardHeader>
                    <CardTitle id="decisions-heading">
                        Recent decisions
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    {decisions.length === 0 ? (
                        <EmptyState message="No recent merge decisions." />
                    ) : (
                        <ul className="space-y-3">
                            {decisions.map((decision) => (
                                <li
                                    key={decision.id}
                                    className="rounded-lg border p-4"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <StatusBadge value={decision.action} />
                                        {decision.simulated && (
                                            <Badge variant="secondary">
                                                Simulated
                                            </Badge>
                                        )}
                                    </div>
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        {decision.ticketId ??
                                            'Ticket unavailable'}{' '}
                                        · {humanize(decision.actualState)} ·{' '}
                                        {formatDate(decision.decidedAt)}
                                    </p>
                                    <Button
                                        asChild
                                        size="sm"
                                        variant="outline"
                                        className="mt-3"
                                    >
                                        <Link href={decision.contextUrl}>
                                            Review decision
                                        </Link>
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>
        </section>
    );
}

/**
 * Render a consistent status badge without encoding business rules in React.
 */
function StatusBadge({ value }: { value: string }) {
    return <Badge variant={statusVariant(value)}>{humanize(value)}</Badge>;
}

/**
 * Map display-only status severity to existing badge variants.
 */
function statusVariant(value: string): BadgeVariant {
    const normalized = value.toLowerCase();

    if (
        normalized.includes('blocked') ||
        normalized.includes('failed') ||
        normalized.includes('reject')
    ) {
        return 'destructive';
    }

    if (
        normalized.includes('waiting') ||
        normalized.includes('retry') ||
        normalized.includes('unverified') ||
        normalized.includes('defer')
    ) {
        return 'secondary';
    }

    if (
        normalized.includes('completed') ||
        normalized.includes('approved') ||
        normalized.includes('ready') ||
        normalized.includes('running') ||
        normalized.includes('working')
    ) {
        return 'default';
    }

    return 'outline';
}

/**
 * Render a calm, programmatically readable empty state.
 */
function EmptyState({ message }: { message: string }) {
    return (
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
            <Clock3 className="size-4" aria-hidden="true" />
            {message}
        </p>
    );
}

/**
 * Convert stable enum-like values into readable labels.
 */
function humanize(value: string): string {
    return value
        .replaceAll('_', ' ')
        .replaceAll('-', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

/**
 * Format ISO timestamps while preserving an explicit fallback.
 */
function formatDate(value: string): string {
    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? 'Unknown time'
        : date.toLocaleString();
}
