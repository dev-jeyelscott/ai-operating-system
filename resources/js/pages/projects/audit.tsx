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
import type { ComponentType, ReactNode } from 'react';
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
 * Render the project-scoped execution inspector and authoritative audit history.
 */
export default function ProjectAuditPage({
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
                            immutable artifact summaries, and execution costs
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

                <AuditFilters
                    auditUrl={auditUrl}
                    executions={executions}
                    filters={filters}
                    filterOptions={filterOptions}
                />

                <section className="grid gap-6 xl:grid-cols-[minmax(20rem,0.9fr)_minmax(0,2fr)]">
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
                                {executions.map((execution) => (
                                    <ExecutionListItem
                                        key={execution.id}
                                        execution={execution}
                                        isSelected={
                                            selectedExecution?.id ===
                                            execution.id
                                        }
                                        auditUrl={auditUrl}
                                        filters={filters}
                                    />
                                ))}
                            </ul>
                        )}
                    </aside>

                    <section
                        aria-labelledby="execution-inspector-heading"
                        className="rounded-xl border bg-card p-5 shadow-sm"
                    >
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <h2
                                        id="execution-inspector-heading"
                                        className="font-semibold"
                                    >
                                        Execution inspector
                                    </h2>

                                    {selectedExecution?.isSimulated ===
                                        true && <SimulationBadge />}
                                </div>

                                <p className="mt-1 text-sm text-muted-foreground">
                                    Attempts, retries, errors, costs, and
                                    immutable artifact summaries
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

                <AuditTimeline
                    auditUrl={auditUrl}
                    filters={filters}
                    timeline={timeline}
                />
            </main>
        </>
    );
}

/**
 * Render the complete audit filter form.
 */
function AuditFilters({
    auditUrl,
    executions,
    filters,
    filterOptions,
}: {
    auditUrl: string;
    executions: ExecutionSummary[];
    filters: Filters;
    filterOptions: Props['filterOptions'];
}) {
    return (
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
                            <option key={execution.id} value={execution.id}>
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
                            <option key={option.value} value={option.value}>
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
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </FilterField>

                <FilterField label="Subject ID">
                    <input
                        name="subject_id"
                        defaultValue={filters.subjectId ?? ''}
                        maxLength={191}
                        placeholder="Optional subject ID"
                        className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                    />
                </FilterField>

                <FilterField label="Correlation ID">
                    <input
                        name="correlation_id"
                        defaultValue={filters.correlationId ?? ''}
                        maxLength={128}
                        placeholder="Trace one request"
                        className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                    />
                </FilterField>

                <FilterField label="Causation ID">
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
                        <option value="newest_first">Newest first</option>
                        <option value="oldest_first">Oldest first</option>
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
    );
}

/**
 * Render one selectable execution summary.
 */
function ExecutionListItem({
    execution,
    isSelected,
    auditUrl,
    filters,
}: {
    execution: ExecutionSummary;
    isSelected: boolean;
    auditUrl: string;
    filters: Filters;
}) {
    return (
        <li>
            <Link
                href={buildTimelineUrl(auditUrl, filters, {
                    execution: execution.id,
                    cursor: null,
                })}
                preserveScroll
                aria-current={isSelected ? 'page' : undefined}
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
                            {humanize(execution.capability)}
                        </p>

                        <p className="mt-1 truncate font-mono text-xs text-muted-foreground">
                            {execution.id}
                        </p>
                    </div>

                    <StatusBadge status={execution.status.value}>
                        {execution.status.label}
                    </StatusBadge>
                </div>

                <dl className="mt-4 grid grid-cols-2 gap-3 text-xs">
                    <Metric
                        label="Provider"
                        value={humanize(
                            execution.latestProvider ?? 'not assigned',
                        )}
                    />

                    <Metric
                        label="Reasoning"
                        value={humanize(execution.requestedReasoning)}
                    />

                    <Metric
                        label="Attempts"
                        value={String(execution.attemptCount)}
                    />

                    <Metric
                        label="Retries"
                        value={String(execution.retryCount)}
                    />

                    <Metric
                        label="Errors"
                        value={String(execution.errorCount)}
                    />

                    <Metric
                        label="Artifacts"
                        value={String(execution.artifactCount)}
                    />

                    <Metric
                        label="Estimated cost"
                        value={formatCost(
                            execution.estimatedCost,
                            execution.costCurrency,
                        )}
                    />

                    <Metric
                        label="Actual cost"
                        value={formatCost(
                            execution.actualCost,
                            execution.costCurrency,
                        )}
                    />
                </dl>

                {execution.isSimulated && (
                    <div className="mt-4">
                        <SimulationBadge />
                    </div>
                )}
            </Link>
        </li>
    );
}

/**
 * Render the selected execution's status, attempts, and artifacts.
 */
function ExecutionInspector({ execution }: { execution: SelectedExecution }) {
    return (
        <div className="mt-5 space-y-6">
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <SummaryCard
                    icon={Activity}
                    label="Status"
                    value={execution.status.label}
                />

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
                    icon={AlertTriangle}
                    label="Errors"
                    value={String(execution.errorCount)}
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
                    icon={CircleDollarSign}
                    label="Actual cost"
                    value={formatCost(
                        execution.actualCost,
                        execution.costCurrency,
                    )}
                />
            </div>

            <dl className="grid gap-4 rounded-lg border p-4 text-sm md:grid-cols-2 xl:grid-cols-3">
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
                    label="Provider"
                    value={humanize(execution.latestProvider ?? 'not assigned')}
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

                <Metric
                    label="Started"
                    value={formatDate(execution.startedAt)}
                />

                <Metric
                    label="Finished"
                    value={formatDate(execution.finishedAt)}
                />
            </dl>

            <AttemptHistory attempts={execution.attempts} />
            <ArtifactList artifacts={execution.artifacts} />
        </div>
    );
}

/**
 * Render every immutable provider attempt for the selected execution.
 */
function AttemptHistory({ attempts }: { attempts: ExecutionAttempt[] }) {
    return (
        <section aria-labelledby="attempt-history-heading">
            <h3 id="attempt-history-heading" className="font-medium">
                Attempt history
            </h3>

            {attempts.length === 0 ? (
                <EmptyState
                    title="No attempts recorded"
                    description="No provider attempt has been recorded for this execution."
                />
            ) : (
                <ol className="mt-3 space-y-3">
                    {attempts.map((attempt) => (
                        <li key={attempt.id} className="rounded-lg border p-4">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p className="font-medium">
                                        Attempt {attempt.attemptNumber}
                                    </p>

                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {humanize(attempt.provider)} ·{' '}
                                        {humanize(attempt.effectiveReasoning)}{' '}
                                        reasoning
                                    </p>
                                </div>

                                <div className="flex flex-wrap items-center gap-2">
                                    {attempt.simulationMode !== null && (
                                        <SimulationBadge />
                                    )}

                                    <StatusBadge status={attempt.status.value}>
                                        {attempt.status.label}
                                    </StatusBadge>
                                </div>
                            </div>

                            <dl className="mt-4 grid gap-3 text-sm md:grid-cols-2 xl:grid-cols-3">
                                <Metric
                                    label="Requested reasoning"
                                    value={humanize(attempt.requestedReasoning)}
                                />

                                <Metric
                                    label="Effective reasoning"
                                    value={humanize(attempt.effectiveReasoning)}
                                />

                                <Metric
                                    label="Reasoning source"
                                    value={humanize(attempt.reasoningSource)}
                                />

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
                                    value={formatConfidence(attempt.confidence)}
                                />

                                <Metric
                                    label="Reported state"
                                    value={humanize(
                                        attempt.reportedState ?? 'not reported',
                                    )}
                                />

                                <Metric
                                    label="Observed state"
                                    value={humanize(
                                        attempt.observedState ?? 'not observed',
                                    )}
                                />

                                <Metric
                                    label="Actual state"
                                    value={humanize(
                                        attempt.actualState ?? 'unverified',
                                    )}
                                />

                                <Metric
                                    label="Started"
                                    value={formatDate(attempt.startedAt)}
                                />

                                <Metric
                                    label="Finished"
                                    value={formatDate(attempt.finishedAt)}
                                />

                                <Metric
                                    label="Heartbeat"
                                    value={formatDate(attempt.heartbeatAt)}
                                />
                            </dl>

                            {attempt.simulationMode !== null && (
                                <p className="mt-4 rounded-md border border-dashed p-3 text-sm text-muted-foreground">
                                    Simulation mode:{' '}
                                    {humanize(attempt.simulationMode)}. Actual
                                    state remains unverified.
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
                                            : attempt.error.retryable === false
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
        </section>
    );
}

/**
 * Render safe artifact summaries without exposing raw artifact bodies.
 */
function ArtifactList({ artifacts }: { artifacts: ExecutionArtifact[] }) {
    return (
        <section aria-labelledby="artifact-list-heading">
            <h3 id="artifact-list-heading" className="font-medium">
                Artifacts
            </h3>

            {artifacts.length === 0 ? (
                <EmptyState
                    title="No artifacts recorded"
                    description="No artifacts have been recorded for this execution."
                />
            ) : (
                <ul className="mt-3 grid gap-3 lg:grid-cols-2">
                    {artifacts.map((artifact) => (
                        <li key={artifact.id} className="rounded-lg border p-4">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p className="font-medium">
                                        {artifact.name}
                                    </p>

                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {humanize(artifact.type)} ·{' '}
                                        {humanize(artifact.provider)}
                                    </p>
                                </div>

                                <div className="flex flex-wrap items-center gap-2">
                                    {artifact.isSimulated && (
                                        <SimulationBadge />
                                    )}

                                    <StatusBadge status={artifact.actualState}>
                                        {humanize(artifact.actualState)}
                                    </StatusBadge>
                                </div>
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
                                    value={formatConfidence(
                                        artifact.confidence,
                                    )}
                                />
                            </dl>

                            {artifact.isSimulated && (
                                <p className="mt-4 rounded-md border border-dashed p-3 text-xs text-muted-foreground">
                                    This simulated artifact is not verified
                                    implementation, CI, QA, merge, or deployment
                                    evidence.
                                </p>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/**
 * Render the authoritative, cursor-paginated audit timeline.
 */
function AuditTimeline({
    auditUrl,
    filters,
    timeline,
}: {
    auditUrl: string;
    filters: Filters;
    timeline: Props['timeline'];
}) {
    return (
        <section
            aria-labelledby="audit-timeline-heading"
            className="rounded-xl border bg-card p-5 shadow-sm"
        >
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 id="audit-timeline-heading" className="font-semibold">
                        Authoritative audit timeline
                    </h2>

                    <p className="mt-1 text-sm text-muted-foreground">
                        Ordered by immutable sequence and event timestamp
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
                            className="rounded-lg border p-4"
                        >
                            <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                <div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <StatusBadge
                                            status={event.eventType.value}
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
                                        Subject: {humanize(event.subject.type)}{' '}
                                        · {event.subject.id}
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
                                        value={event.executionId ?? 'None'}
                                        mono
                                    />

                                    <Metric
                                        label="Correlation ID"
                                        value={event.correlationId ?? 'None'}
                                        mono
                                    />

                                    <Metric
                                        label="Causation ID"
                                        value={event.causationId ?? 'None'}
                                        mono
                                    />

                                    <Metric
                                        label="Schema version"
                                        value={String(event.schemaVersion)}
                                    />
                                </dl>
                            </details>
                        </li>
                    ))}
                </ol>
            )}

            {(timeline.previousCursor !== null ||
                timeline.nextCursor !== null) && (
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
            )}
        </section>
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
    children: ReactNode;
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
    icon: ComponentType<{
        className?: string;
        'aria-hidden'?: boolean;
    }>;
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
 * Render a persistent non-deception indicator for simulation output.
 */
function SimulationBadge() {
    return (
        <span className="inline-flex rounded-full border border-dashed border-amber-600/40 bg-amber-500/10 px-2.5 py-1 text-xs font-medium text-amber-700 dark:text-amber-300">
            Simulated / Unverified
        </span>
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
    children: ReactNode;
}) {
    const normalizedStatus = status.toLowerCase();

    const destructive =
        normalizedStatus.includes('failed') ||
        normalizedStatus.includes('rejected') ||
        normalizedStatus.includes('cancelled') ||
        normalizedStatus.includes('timed_out');

    const active =
        normalizedStatus.includes('running') ||
        normalizedStatus.includes('started') ||
        normalizedStatus.includes('scheduled');

    const successful =
        normalizedStatus.includes('completed') ||
        normalizedStatus.includes('granted') ||
        normalizedStatus.includes('succeeded');

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
 * Render an accessible empty result state with a semantic heading.
 */
function EmptyState({
    title,
    description,
}: {
    title: string;
    description: string;
}) {
    return (
        <div
            role="status"
            className="mt-5 rounded-lg border border-dashed p-8 text-center"
        >
            <h3 className="font-medium">{title}</h3>

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
 * Format a nullable confidence ratio as a percentage.
 */
function formatConfidence(value: string | null): string {
    if (value === null) {
        return 'Not reported';
    }

    const confidence = Number(value);

    if (!Number.isFinite(confidence)) {
        return value;
    }

    return `${(confidence * 100).toFixed(1)}%`;
}

/**
 * Format nullable execution costs using their persisted currency.
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
