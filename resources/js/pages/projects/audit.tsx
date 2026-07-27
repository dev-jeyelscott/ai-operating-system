import { Form, Head, Link } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowLeft,
    Boxes,
    CircleDollarSign,
    Clock3,
    FileStack,
    RefreshCw,
} from 'lucide-react';
import { Button } from '@/components/ui/button';

type SelectOption = {
    value: string;
    label: string;
};

type StatusValue = {
    value: string;
    label: string;
};

type Organization = {
    id: number;
    name: string;
    slug: string;
};

type Project = {
    id: number;
    name: string;
    slug: string;
    status: StatusValue;
};

type Filters = {
    execution: string | null;
    eventType: string | null;
    subjectType: string | null;
    subjectId: string | null;
    correlationId: string | null;
    causationId: string | null;
    order: string;
    perPage: number;
};

type ExecutionSummary = {
    id: string;
    capability: string;
    logicalRole: string | null;
    status: StatusValue;
    requestedReasoning: string;
    attemptCount: number;
    retryCount: number;
    retryLimit: number;
    artifactCount: number;
    errorCount: number;
    estimatedCost: string | null;
    actualCost: string | null;
    costCurrency: string | null;
    latestProvider: string | null;
    isSimulated: boolean;
    correlationId: string;
    startedAt: string | null;
    finishedAt: string | null;
    nextAttemptAt: string | null;
    createdAt: string | null;
};

type ExecutionAttempt = {
    id: number;
    attemptNumber: number;
    status: StatusValue;
    provider: string;
    modelIdentifier: string | null;
    requestedReasoning: string;
    effectiveReasoning: string;
    reasoningSource: string;
    reasoningEscalationReason: string | null;
    simulationMode: string | null;
    simulationSeed: string | null;
    reportedState: string | null;
    observedState: string | null;
    actualState: string | null;
    confidence: string | null;
    estimatedCost: string | null;
    actualCost: string | null;
    costCurrency: string | null;
    error: {
        code: string | null;
        message: string | null;
        retryable: boolean | null;
        retryDelaySeconds: number | null;
    } | null;
    deadlineAt: string | null;
    heartbeatAt: string | null;
    startedAt: string | null;
    finishedAt: string | null;
};

type ExecutionArtifact = {
    id: string;
    type: string;
    name: string;
    provider: string;
    externalReference: string | null;
    mediaType: string | null;
    checksumSha256: string | null;
    byteSize: number | null;
    simulationMode: string | null;
    simulationSeed: string | null;
    assumptions: string[];
    confidence: string | null;
    evidenceStillRequired: boolean;
    evidenceCount: number;
    actualState: string;
    isSimulated: boolean;
    createdAt: string;
};

type SelectedExecution = ExecutionSummary & {
    idempotencyKey: string;
    timeoutSeconds: number;
    cancellationReason: string | null;
    cancelRequestedAt: string | null;
    cancelledAt: string | null;
    attempts: ExecutionAttempt[];
    artifacts: ExecutionArtifact[];
};

type AuditEvent = {
    sequence: number;
    eventId: string;
    eventType: StatusValue;
    actor: {
        type: string;
        id: string;
    };
    subject: {
        type: string;
        id: string;
    };
    executionId: string | null;
    correlationId: string | null;
    causationId: string | null;
    schemaVersion: number;
    occurredAt: string;
};

type Props = {
    organization: Organization;
    project: Project;
    auditUrl: string;
    projectUrl: string;
    filters: Filters;
    filterOptions: {
        eventTypes: SelectOption[];
        subjectTypes: SelectOption[];
    };
    executions: ExecutionSummary[];
    selectedExecution: SelectedExecution | null;
    timeline: {
        data: AuditEvent[];
        perPage: number;
        nextCursor: string | null;
        previousCursor: string | null;
    };
};

/**
 * Render the project-scoped execution inspector and audit history.
 */
export default function ProjectAudit({
    organization,
    project,
    auditUrl,
    projectUrl,
    filters,
    filterOptions,
    executions,
    selectedExecution,
    timeline,
}: Props) {
    return (
        <>
            <Head title={`${project.name} execution audit`} />

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
                                Execution and audit
                            </h1>

                            <StatusBadge status={project.status.value}>
                                {project.status.label}
                            </StatusBadge>
                        </div>

                        <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                            Inspect authoritative transitions, provider
                            attempts, retry history, safe error details,
                            immutable artifacts, and execution cost estimates
                            for {project.name}.
                        </p>
                    </div>

                    <div className="rounded-lg border bg-card px-4 py-3 text-sm">
                        <p className="font-medium">{organization.name}</p>
                        <p className="text-muted-foreground">
                            Project-scoped read-only view
                        </p>
                    </div>
                </header>

                <section
                    aria-labelledby="audit-filters-heading"
                    className="rounded-xl border bg-card p-5 shadow-sm"
                >
                    <h2 id="audit-filters-heading" className="font-semibold">
                        Timeline filters
                    </h2>

                    <Form
                        action={auditUrl}
                        method="get"
                        className="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4"
                    >
                        <FilterField label="Execution">
                            <select
                                name="execution"
                                defaultValue={filters.execution ?? ''}
                                className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                            >
                                <option value="">All executions</option>

                                {executions.map((execution) => (
                                    <option
                                        key={execution.id}
                                        value={execution.id}
                                    >
                                        {humanize(execution.capability)} ·{' '}
                                        {execution.id.slice(-8)}
                                    </option>
                                ))}
                            </select>
                        </FilterField>

                        <FilterField label="Event type">
                            <select
                                name="event_type"
                                defaultValue={filters.eventType ?? ''}
                                className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                            >
                                <option value="">All event types</option>

                                {filterOptions.eventTypes.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </FilterField>

                        <FilterField label="Subject type">
                            <select
                                name="subject_type"
                                defaultValue={filters.subjectType ?? ''}
                                className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                            >
                                <option value="">All subject types</option>

                                {filterOptions.subjectTypes.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </FilterField>

                        <FilterField label="Subject identifier">
                            <input
                                name="subject_id"
                                defaultValue={filters.subjectId ?? ''}
                                maxLength={191}
                                placeholder="Optional subject ID"
                                className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                            />
                        </FilterField>

                        <FilterField label="Correlation identifier">
                            <input
                                name="correlation_id"
                                defaultValue={filters.correlationId ?? ''}
                                maxLength={128}
                                placeholder="Trace one request"
                                className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                            />
                        </FilterField>

                        <FilterField label="Causation identifier">
                            <input
                                name="causation_id"
                                defaultValue={filters.causationId ?? ''}
                                maxLength={128}
                                placeholder="Trace one cause"
                                className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                            />
                        </FilterField>

                        <FilterField label="Order">
                            <select
                                name="order"
                                defaultValue={filters.order}
                                className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                            >
                                <option value="newest_first">
                                    Newest first
                                </option>
                                <option value="oldest_first">
                                    Oldest first
                                </option>
                            </select>
                        </FilterField>

                        <FilterField label="Events per page">
                            <select
                                name="per_page"
                                defaultValue={String(filters.perPage)}
                                className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                            >
                                <option value="10">10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </FilterField>

                        <div className="flex flex-wrap items-end gap-2 md:col-span-2 xl:col-span-4">
                            <Button type="submit">
                                <Activity aria-hidden="true" />
                                Apply filters
                            </Button>

                            <Button asChild variant="outline">
                                <Link href={auditUrl}>Clear filters</Link>
                            </Button>
                        </div>
                    </Form>
                </section>

                <section className="grid gap-6 xl:grid-cols-[minmax(18rem,0.8fr)_minmax(0,2fr)]">
                    <aside
                        aria-labelledby="recent-executions-heading"
                        className="rounded-xl border bg-card p-5 shadow-sm"
                    >
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <h2
                                    id="recent-executions-heading"
                                    className="font-semibold"
                                >
                                    Recent executions
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Latest 25 project executions
                                </p>
                            </div>

                            <Boxes
                                aria-hidden="true"
                                className="size-5 text-muted-foreground"
                            />
                        </div>

                        {executions.length === 0 ? (
                            <EmptyState
                                title="No executions yet"
                                description="Start the project workflow before execution attempts can appear here."
                            />
                        ) : (
                            <ul className="mt-4 space-y-3">
                                {executions.map((execution) => {
                                    const isSelected =
                                        selectedExecution?.id === execution.id;

                                    return (
                                        <li key={execution.id}>
                                            <Link
                                                href={buildTimelineUrl(
                                                    auditUrl,
                                                    filters,
                                                    {
                                                        execution: execution.id,
                                                        cursor: null,
                                                    },
                                                )}
                                                preserveScroll
                                                className={[
                                                    'block rounded-lg border p-4 transition-colors',
                                                    isSelected
                                                        ? 'border-primary bg-primary/5'
                                                        : 'hover:bg-muted/50',
                                                ].join(' ')}
                                            >
                                                <div className="flex items-start justify-between gap-3">
                                                    <div className="min-w-0">
                                                        <p className="truncate font-medium">
                                                            {humanize(
                                                                execution.capability,
                                                            )}
                                                        </p>
                                                        <p className="mt-1 truncate font-mono text-xs text-muted-foreground">
                                                            {execution.id}
                                                        </p>
                                                    </div>

                                                    <StatusBadge
                                                        status={
                                                            execution.status
                                                                .value
                                                        }
                                                    >
                                                        {execution.status.label}
                                                    </StatusBadge>
                                                </div>

                                                <dl className="mt-3 grid grid-cols-2 gap-3 text-xs">
                                                    <Metric
                                                        label="Attempts"
                                                        value={String(
                                                            execution.attemptCount,
                                                        )}
                                                    />
                                                    <Metric
                                                        label="Retries"
                                                        value={String(
                                                            execution.retryCount,
                                                        )}
                                                    />
                                                    <Metric
                                                        label="Artifacts"
                                                        value={String(
                                                            execution.artifactCount,
                                                        )}
                                                    />
                                                    <Metric
                                                        label="Estimated"
                                                        value={formatCost(
                                                            execution.estimatedCost,
                                                            execution.costCurrency,
                                                        )}
                                                    />
                                                </dl>

                                                {execution.isSimulated && (
                                                    <p className="mt-3 rounded-md border border-dashed px-2 py-1 text-xs text-muted-foreground">
                                                        Simulated and unverified
                                                    </p>
                                                )}
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </aside>

                    <section
                        aria-labelledby="execution-inspector-heading"
                        className="rounded-xl border bg-card p-5 shadow-sm"
                    >
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <h2
                                    id="execution-inspector-heading"
                                    className="font-semibold"
                                >
                                    Execution inspector
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Attempts, retries, errors, costs, and
                                    immutable artifacts
                                </p>
                            </div>

                            <FileStack
                                aria-hidden="true"
                                className="size-5 text-muted-foreground"
                            />
                        </div>

                        {selectedExecution === null ? (
                            <EmptyState
                                title="Nothing to inspect"
                                description="No execution exists for this project yet."
                            />
                        ) : (
                            <ExecutionInspector execution={selectedExecution} />
                        )}
                    </section>
                </section>

                <section
                    aria-labelledby="audit-timeline-heading"
                    className="rounded-xl border bg-card p-5 shadow-sm"
                >
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2
                                id="audit-timeline-heading"
                                className="font-semibold"
                            >
                                Authoritative audit timeline
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Ordered by immutable sequence and event
                                timestamp
                            </p>
                        </div>

                        <p className="text-sm text-muted-foreground">
                            {timeline.data.length} event
                            {timeline.data.length === 1 ? '' : 's'} on this page
                        </p>
                    </div>

                    {timeline.data.length === 0 ? (
                        <EmptyState
                            title="No matching audit events"
                            description="Clear or change the selected filters."
                        />
                    ) : (
                        <ol className="mt-6 space-y-4">
                            {timeline.data.map((event) => (
                                <li
                                    key={event.eventId}
                                    className="relative rounded-lg border p-4"
                                >
                                    <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                        <div>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <StatusBadge
                                                    status={
                                                        event.eventType.value
                                                    }
                                                >
                                                    {event.eventType.label}
                                                </StatusBadge>

                                                <span className="font-mono text-xs text-muted-foreground">
                                                    Sequence {event.sequence}
                                                </span>
                                            </div>

                                            <p className="mt-3 text-sm">
                                                <span className="font-medium">
                                                    {humanize(event.actor.type)}
                                                </span>{' '}
                                                <span className="text-muted-foreground">
                                                    {event.actor.id}
                                                </span>
                                            </p>

                                            <p className="mt-1 text-sm text-muted-foreground">
                                                Subject:{' '}
                                                {humanize(event.subject.type)} ·{' '}
                                                {event.subject.id}
                                            </p>
                                        </div>

                                        <time
                                            dateTime={event.occurredAt}
                                            className="text-sm text-muted-foreground"
                                        >
                                            {formatDate(event.occurredAt)}
                                        </time>
                                    </div>

                                    <details className="mt-4 rounded-md bg-muted/40 p-3 text-xs">
                                        <summary className="cursor-pointer font-medium">
                                            Trace identifiers
                                        </summary>

                                        <dl className="mt-3 grid gap-3 md:grid-cols-2">
                                            <Metric
                                                label="Event ID"
                                                value={event.eventId}
                                                mono
                                            />
                                            <Metric
                                                label="Execution ID"
                                                value={
                                                    event.executionId ?? 'None'
                                                }
                                                mono
                                            />
                                            <Metric
                                                label="Correlation ID"
                                                value={
                                                    event.correlationId ??
                                                    'None'
                                                }
                                                mono
                                            />
                                            <Metric
                                                label="Causation ID"
                                                value={
                                                    event.causationId ?? 'None'
                                                }
                                                mono
                                            />
                                            <Metric
                                                label="Schema version"
                                                value={String(
                                                    event.schemaVersion,
                                                )}
                                            />
                                        </dl>
                                    </details>
                                </li>
                            ))}
                        </ol>
                    )}

                    <nav
                        aria-label="Audit timeline pagination"
                        className="mt-6 flex items-center justify-between gap-3"
                    >
                        {timeline.previousCursor !== null ? (
                            <Button asChild variant="outline">
                                <Link
                                    href={buildTimelineUrl(auditUrl, filters, {
                                        cursor: timeline.previousCursor,
                                    })}
                                    preserveScroll
                                >
                                    Previous
                                </Link>
                            </Button>
                        ) : (
                            <span />
                        )}

                        {timeline.nextCursor !== null && (
                            <Button asChild variant="outline">
                                <Link
                                    href={buildTimelineUrl(auditUrl, filters, {
                                        cursor: timeline.nextCursor,
                                    })}
                                    preserveScroll
                                >
                                    Next
                                </Link>
                            </Button>
                        )}
                    </nav>
                </section>
            </main>
        </>
    );
}

/**
 * Render the selected execution's attempts and artifacts.
 */
function ExecutionInspector({ execution }: { execution: SelectedExecution }) {
    return (
        <div className="mt-5 space-y-6">
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <SummaryCard
                    icon={Clock3}
                    label="Attempts"
                    value={`${execution.attemptCount}/${execution.retryLimit + 1}`}
                />
                <SummaryCard
                    icon={RefreshCw}
                    label="Retries"
                    value={String(execution.retryCount)}
                />
                <SummaryCard
                    icon={CircleDollarSign}
                    label="Estimated cost"
                    value={formatCost(
                        execution.estimatedCost,
                        execution.costCurrency,
                    )}
                />
                <SummaryCard
                    icon={AlertTriangle}
                    label="Errors"
                    value={String(execution.errorCount)}
                />
            </div>

            <dl className="grid gap-4 rounded-lg border p-4 text-sm md:grid-cols-2">
                <Metric label="Execution ID" value={execution.id} mono />
                <Metric
                    label="Capability"
                    value={humanize(execution.capability)}
                />
                <Metric
                    label="Logical role"
                    value={humanize(execution.logicalRole ?? 'unassigned')}
                />
                <Metric
                    label="Requested reasoning"
                    value={humanize(execution.requestedReasoning)}
                />
                <Metric
                    label="Correlation ID"
                    value={execution.correlationId}
                    mono
                />
                <Metric
                    label="Created"
                    value={formatDate(execution.createdAt)}
                />
            </dl>

            <div>
                <h3 className="font-medium">Provider attempts</h3>

                {execution.attempts.length === 0 ? (
                    <p className="mt-3 text-sm text-muted-foreground">
                        No provider attempts have been recorded.
                    </p>
                ) : (
                    <ol className="mt-3 space-y-3">
                        {execution.attempts.map((attempt) => (
                            <li
                                key={attempt.id}
                                className="rounded-lg border p-4"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <p className="font-medium">
                                            Attempt {attempt.attemptNumber}
                                        </p>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {humanize(attempt.provider)} ·{' '}
                                            {humanize(
                                                attempt.effectiveReasoning,
                                            )}{' '}
                                            reasoning
                                        </p>
                                    </div>

                                    <StatusBadge status={attempt.status.value}>
                                        {attempt.status.label}
                                    </StatusBadge>
                                </div>

                                <dl className="mt-4 grid gap-3 text-sm md:grid-cols-3">
                                    <Metric
                                        label="Estimated cost"
                                        value={formatCost(
                                            attempt.estimatedCost,
                                            attempt.costCurrency,
                                        )}
                                    />
                                    <Metric
                                        label="Actual cost"
                                        value={formatCost(
                                            attempt.actualCost,
                                            attempt.costCurrency,
                                        )}
                                    />
                                    <Metric
                                        label="Confidence"
                                        value={
                                            attempt.confidence === null
                                                ? 'Not reported'
                                                : `${(
                                                      Number(
                                                          attempt.confidence,
                                                      ) * 100
                                                  ).toFixed(1)}%`
                                        }
                                    />
                                    <Metric
                                        label="Reported state"
                                        value={humanize(
                                            attempt.reportedState ??
                                                'not reported',
                                        )}
                                    />
                                    <Metric
                                        label="Observed state"
                                        value={humanize(
                                            attempt.observedState ??
                                                'not observed',
                                        )}
                                    />
                                    <Metric
                                        label="Actual state"
                                        value={humanize(
                                            attempt.actualState ?? 'unverified',
                                        )}
                                    />
                                </dl>

                                {attempt.simulationMode !== null && (
                                    <p className="mt-4 rounded-md border border-dashed p-3 text-sm text-muted-foreground">
                                        Simulation mode:{' '}
                                        {humanize(attempt.simulationMode)}.
                                        Actual state remains unverified.
                                    </p>
                                )}

                                {attempt.error !== null && (
                                    <div
                                        role="alert"
                                        className="mt-4 rounded-md border border-destructive/30 bg-destructive/5 p-3"
                                    >
                                        <p className="font-medium text-destructive">
                                            {attempt.error.code ??
                                                'Execution error'}
                                        </p>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {attempt.error.message ??
                                                'No safe error message was recorded.'}
                                        </p>
                                        <p className="mt-2 text-xs text-muted-foreground">
                                            Retryable:{' '}
                                            {attempt.error.retryable === true
                                                ? 'Yes'
                                                : attempt.error.retryable ===
                                                    false
                                                  ? 'No'
                                                  : 'Unknown'}
                                            {attempt.error.retryDelaySeconds !==
                                                null &&
                                                ` · Delay ${attempt.error.retryDelaySeconds}s`}
                                        </p>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ol>
                )}
            </div>

            <div>
                <h3 className="font-medium">Artifacts</h3>

                {execution.artifacts.length === 0 ? (
                    <p className="mt-3 text-sm text-muted-foreground">
                        No artifacts have been recorded for this execution.
                    </p>
                ) : (
                    <ul className="mt-3 grid gap-3 lg:grid-cols-2">
                        {execution.artifacts.map((artifact) => (
                            <li
                                key={artifact.id}
                                className="rounded-lg border p-4"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <p className="font-medium">
                                            {artifact.name}
                                        </p>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {humanize(artifact.type)} ·{' '}
                                            {humanize(artifact.provider)}
                                        </p>
                                    </div>

                                    <StatusBadge status={artifact.actualState}>
                                        {humanize(artifact.actualState)}
                                    </StatusBadge>
                                </div>

                                <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                                    <Metric
                                        label="Evidence records"
                                        value={String(artifact.evidenceCount)}
                                    />
                                    <Metric
                                        label="Evidence required"
                                        value={
                                            artifact.evidenceStillRequired
                                                ? 'Yes'
                                                : 'No'
                                        }
                                    />
                                    <Metric
                                        label="Created"
                                        value={formatDate(artifact.createdAt)}
                                    />
                                    <Metric
                                        label="Confidence"
                                        value={
                                            artifact.confidence === null
                                                ? 'Not reported'
                                                : `${(
                                                      Number(
                                                          artifact.confidence,
                                                      ) * 100
                                                  ).toFixed(1)}%`
                                        }
                                    />
                                </dl>

                                {artifact.isSimulated && (
                                    <p className="mt-4 rounded-md border border-dashed p-3 text-xs text-muted-foreground">
                                        Simulated artifact. This is not verified
                                        implementation, CI, QA, merge, or
                                        deployment evidence.
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}

/**
 * Render one labelled filter control.
 */
function FilterField({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <label className="grid gap-2 text-sm font-medium">
            {label}
            {children}
        </label>
    );
}

/**
 * Render a compact execution metric card.
 */
function SummaryCard({
    icon: Icon,
    label,
    value,
}: {
    icon: React.ComponentType<{ className?: string; 'aria-hidden'?: boolean }>;
    label: string;
    value: string;
}) {
    return (
        <div className="rounded-lg border p-4">
            <div className="flex items-center gap-2 text-muted-foreground">
                <Icon aria-hidden={true} className="size-4" />
                <span className="text-xs font-medium tracking-wide uppercase">
                    {label}
                </span>
            </div>

            <p className="mt-2 text-lg font-semibold">{value}</p>
        </div>
    );
}

/**
 * Render one definition-list metric.
 */
function Metric({
    label,
    value,
    mono = false,
}: {
    label: string;
    value: string;
    mono?: boolean;
}) {
    return (
        <div className="min-w-0">
            <dt className="text-muted-foreground">{label}</dt>
            <dd
                className={[
                    'mt-1 font-medium break-words',
                    mono ? 'font-mono text-xs' : '',
                ].join(' ')}
            >
                {value}
            </dd>
        </div>
    );
}

/**
 * Render one semantic status badge.
 */
function StatusBadge({
    status,
    children,
}: {
    status: string;
    children: React.ReactNode;
}) {
    const destructive =
        status.includes('failed') ||
        status.includes('rejected') ||
        status.includes('cancelled') ||
        status.includes('timed_out');

    const active =
        status.includes('running') ||
        status.includes('started') ||
        status.includes('scheduled');

    const successful =
        status.includes('completed') ||
        status.includes('granted') ||
        status.includes('succeeded');

    return (
        <span
            className={[
                'inline-flex rounded-full border px-2.5 py-1 text-xs font-medium',
                destructive
                    ? 'border-destructive/30 bg-destructive/10 text-destructive'
                    : active
                      ? 'border-primary/30 bg-primary/10 text-primary'
                      : successful
                        ? 'border-border bg-muted text-foreground'
                        : 'border-border bg-background text-muted-foreground',
            ].join(' ')}
        >
            {children}
        </span>
    );
}

/**
 * Render an accessible empty result state.
 */
function EmptyState({
    title,
    description,
}: {
    title: string;
    description: string;
}) {
    return (
        <div className="mt-5 rounded-lg border border-dashed p-8 text-center">
            <p className="font-medium">{title}</p>
            <p className="mt-2 text-sm text-muted-foreground">{description}</p>
        </div>
    );
}

/**
 * Build a filter-preserving audit URL without relying on browser globals.
 */
function buildTimelineUrl(
    auditUrl: string,
    filters: Filters,
    overrides: {
        execution?: string | null;
        cursor?: string | null;
    },
): string {
    const parameters = new URLSearchParams();

    const values: Record<string, string | number | null> = {
        execution:
            overrides.execution === undefined
                ? filters.execution
                : overrides.execution,
        event_type: filters.eventType,
        subject_type: filters.subjectType,
        subject_id: filters.subjectId,
        correlation_id: filters.correlationId,
        causation_id: filters.causationId,
        order: filters.order,
        per_page: filters.perPage,
        cursor: overrides.cursor ?? null,
    };

    Object.entries(values).forEach(([key, value]) => {
        if (value !== null && value !== '') {
            parameters.set(key, String(value));
        }
    });

    const query = parameters.toString();

    return query === '' ? auditUrl : `${auditUrl}?${query}`;
}

/**
 * Convert a stored machine value into readable text.
 */
function humanize(value: string): string {
    return value
        .replaceAll('.', ' ')
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

/**
 * Format one nullable ISO timestamp.
 */
function formatDate(value: string | null): string {
    if (value === null) {
        return 'Not recorded';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('en-US', {
        dateStyle: 'medium',
        timeStyle: 'medium',
    }).format(date);
}

/**
 * Format nullable decimal execution costs using their persisted currency.
 */
function formatCost(value: string | null, currency: string | null): string {
    if (value === null) {
        return 'Not reported';
    }

    const amount = Number(value);

    if (!Number.isFinite(amount)) {
        return value;
    }

    try {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currency ?? 'USD',
            minimumFractionDigits: 2,
            maximumFractionDigits: 8,
        }).format(amount);
    } catch {
        return `${currency ?? 'USD'} ${value}`;
    }
}
