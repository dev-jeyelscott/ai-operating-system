import { Deferred, Head, Link, usePoll, useRemember } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    Ban,
    CheckCircle2,
    Clock3,
    FileCode2,
    FlaskConical,
    GitBranch,
    GitCommitHorizontal,
    GitPullRequestArrow,
    RefreshCw,
    ShieldAlert,
} from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';

type Attempt = {
    id: number;
    number: number;
    status: string;
    provider: string;
    modelIdentifier: string | null;
    requestedReasoning: string;
    effectiveReasoning: string;
    reasoningSource: string;
    reasoningEscalationReason: string | null;
    simulationMode: string | null;
    simulationSeed: string | null;
    actualState: string | null;
    confidence: string | null;
    error: {
        code: string;
        message: string | null;
        retryable: boolean | null;
        retryDelaySeconds: number | null;
    } | null;
    deadlineAt: string | null;
    heartbeatAt: string | null;
    startedAt: string | null;
    finishedAt: string | null;
};

type Artifact = {
    id: string;
    type: string;
    name: string;
    provider: string;
    reference: string | null;
    simulationMode: string | null;
    simulationSeed: string | null;
    assumptions: string[];
    confidence: string | null;
    actualState: string;
    evidenceStillRequired: boolean;
    details: Record<string, unknown>;
    evidence: Array<{
        id: string;
        classification: string;
        type: string;
        provider: string;
        sourceReference: string;
        commitSha: string | null;
        claims: string[];
        confidence: string | null;
        verified: boolean;
        createdAt: string;
    }>;
    createdAt: string;
};

type RepositoryArtifact = {
    name: string;
    reference: string | null;
    kind?: string;
    identifier?: string;
    target_branch?: string | null;
    synthetic?: boolean;
    evidence_still_required?: boolean;
};

export type DevelopmentInspector = {
    execution: {
        id: string;
        capability: string;
        status: string;
        terminal: boolean;
        provider: string;
        requestedReasoning: string;
        attemptCount: number;
        retryLimit: number;
        nextAttemptAt: string | null;
        cancelRequestedAt: string | null;
        cancelledAt: string | null;
        cancellationReason: string | null;
        startedAt: string | null;
        finishedAt: string | null;
    };
    ticket: {
        id: string;
        title: string;
        objective: string;
        status: string;
        actualState: string;
    } | null;
    simulation: {
        simulated: true;
        verified: false;
        evidenceStillRequired: true;
    };
    contextSnapshot: {
        id: number;
        configurationRevision: number;
        identitySchemaVersion: number;
        approvedDocumentSetFingerprint: string;
        approvedDocumentCount: number;
        createdAt: string;
    } | null;
    attempts: Attempt[];
    artifacts: Artifact[];
    plan: string[];
    changedFiles: Array<{ path: string; change_type: string; summary: string }>;
    diffSummary: string | null;
    validations: Array<{ command: string; status: string; summary: string }>;
    repository: {
        branch: RepositoryArtifact | null;
        commit: RepositoryArtifact | null;
        push: RepositoryArtifact | null;
        pullRequest: RepositoryArtifact | null;
    };
    assumptions: string[];
    confidence: string | null;
    risks: string[];
    evidenceGaps: string[];
    missingArtifacts: string[];
    lease: {
        id: string;
        owner: string;
        active: boolean;
        expired: boolean;
        expiredButExecutionLive: boolean;
        expiresAt: string;
        heartbeatAt: string;
        releasedAt: string | null;
        releaseReason: string | null;
        recoveryState: string;
    } | null;
    retry: {
        scheduled: boolean;
        attemptCount: number;
        retryLimit: number;
        nextAttemptAt: string | null;
    };
    error: {
        attemptNumber: number;
        code: string;
        message: string | null;
        retryable: boolean | null;
        retryDelaySeconds: number | null;
    } | null;
    auditTimeline: Array<{
        sequence: number;
        type: string;
        attemptId: number | null;
        leaseId: string | null;
        occurredAt: string;
    }>;
    lifecycleEvents: Array<{
        sequence: number;
        eventId: string;
        name: string;
        schemaVersion: number;
        occurredAt: string;
    }>;
};

export type DevelopmentInspectorPageProps = {
    organization: { id: number; name: string; slug: string };
    project: { id: number; name: string; slug: string };
    queueUrl: string;
    inspector?: DevelopmentInspector;
};

export default function DevelopmentExecutionInspectorPage({
    organization,
    project,
    queueUrl,
    inspector,
}: DevelopmentInspectorPageProps) {
    usePoll(
        5_000,
        { only: ['inspector'] },
        { autoStart: inspector ? !inspector.execution.terminal : true },
    );

    return (
        <>
            <Head title={`${project.name} development execution`} />
            <main className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <Button asChild variant="ghost" size="sm">
                            <Link href={queueUrl} preserveScroll>
                                <ArrowLeft aria-hidden="true" />
                                Back to development queue
                            </Link>
                        </Button>
                        <h1 className="mt-4 text-2xl font-semibold tracking-tight">
                            Layer 2 execution inspector
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Safe simulated-development provenance for{' '}
                            {project.name}.
                        </p>
                    </div>
                    <div className="rounded-lg border bg-card px-4 py-3 text-sm">
                        <p className="font-medium">{organization.name}</p>
                        <p className="text-muted-foreground">
                            Authorized project read model
                        </p>
                    </div>
                </header>

                <SimulationBanners />
                <Deferred data="inspector" fallback={<InspectorSkeleton />}>
                    {inspector ? (
                        <InspectorContent inspector={inspector} />
                    ) : (
                        <DeferredRescue />
                    )}
                </Deferred>
            </main>
        </>
    );
}

export function InspectorContent({
    inspector,
}: {
    inspector: DevelopmentInspector;
}) {
    const [attemptFilter, setAttemptFilter] = useRemember(
        'all',
        'development-inspector-attempt-filter',
    );
    const attempts =
        attemptFilter === 'errors'
            ? inspector.attempts.filter((attempt) => attempt.error !== null)
            : inspector.attempts;

    return (
        <div className="flex flex-col gap-6">
            <ExecutionStateAlert inspector={inspector} />

            <section
                aria-labelledby="overview-heading"
                className="grid gap-4 lg:grid-cols-3"
            >
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>
                            <h2 id="overview-heading">Overview</h2>
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-3 text-sm sm:grid-cols-2">
                        <Detail
                            label="Execution"
                            value={inspector.execution.id}
                            mono
                        />
                        <Detail
                            label="Status"
                            value={humanize(inspector.execution.status)}
                        />
                        <Detail
                            label="Ticket"
                            value={
                                inspector.ticket
                                    ? `${inspector.ticket.id} · ${inspector.ticket.title}`
                                    : 'Ticket unavailable'
                            }
                        />
                        <Detail
                            label="Provider"
                            value={inspector.execution.provider}
                        />
                        <Detail
                            label="Requested reasoning"
                            value={humanize(
                                inspector.execution.requestedReasoning,
                            )}
                        />
                        <Detail
                            label="Attempts"
                            value={`${inspector.execution.attemptCount} of ${inspector.execution.retryLimit + 1}`}
                        />
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Context snapshot</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-2 text-sm">
                        {inspector.contextSnapshot ? (
                            <>
                                <Detail
                                    label="Configuration revision"
                                    value={String(
                                        inspector.contextSnapshot
                                            .configurationRevision,
                                    )}
                                />
                                <Detail
                                    label="Approved documents"
                                    value={String(
                                        inspector.contextSnapshot
                                            .approvedDocumentCount,
                                    )}
                                />
                                <Detail
                                    label="Fingerprint"
                                    value={
                                        inspector.contextSnapshot
                                            .approvedDocumentSetFingerprint
                                    }
                                    mono
                                />
                            </>
                        ) : (
                            <p className="text-muted-foreground">
                                Context snapshot metadata is unavailable.
                            </p>
                        )}
                    </CardContent>
                </Card>
            </section>

            <TextListSection
                id="plan"
                title="Implementation plan"
                values={inspector.plan}
                empty="No implementation-plan artifact was recorded."
            />

            <section
                aria-labelledby="attempts-heading"
                className="flex flex-col gap-3"
            >
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2
                            id="attempts-heading"
                            className="text-lg font-semibold"
                        >
                            Attempts
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Ordered provider and reasoning-resolution history.
                        </p>
                    </div>
                    <ToggleGroup
                        type="single"
                        value={attemptFilter}
                        onValueChange={(value) =>
                            value && setAttemptFilter(value)
                        }
                        aria-label="Filter attempts"
                    >
                        <ToggleGroupItem value="all">
                            All attempts
                        </ToggleGroupItem>
                        <ToggleGroupItem value="errors">
                            Errors only
                        </ToggleGroupItem>
                    </ToggleGroup>
                </div>
                {attempts.length === 0 ? (
                    <EmptyCard text="No attempt matches this filter." />
                ) : (
                    <div className="grid gap-4 lg:grid-cols-2">
                        {attempts.map((attempt) => (
                            <AttemptCard key={attempt.id} attempt={attempt} />
                        ))}
                    </div>
                )}
            </section>

            <Timeline inspector={inspector} />
            <ChangedFiles
                files={inspector.changedFiles}
                diffSummary={inspector.diffSummary}
            />
            <Validation validations={inspector.validations} />
            <RepositoryArtifacts repository={inspector.repository} />
            <Evidence inspector={inspector} />
            <LeaseAndRetry inspector={inspector} />
        </div>
    );
}

function SimulationBanners() {
    return (
        <div
            className="grid gap-3 md:grid-cols-3"
            aria-label="Simulation verification status"
        >
            <Alert>
                <FlaskConical aria-hidden="true" />
                <AlertTitle>Simulated execution</AlertTitle>
                <AlertDescription>
                    No repository command or write occurred.
                </AlertDescription>
            </Alert>
            <Alert variant="destructive">
                <ShieldAlert aria-hidden="true" />
                <AlertTitle>Unverified result</AlertTitle>
                <AlertDescription>
                    Simulated output is not verified evidence.
                </AlertDescription>
            </Alert>
            <Alert>
                <AlertTriangle aria-hidden="true" />
                <AlertTitle>Real evidence still required</AlertTitle>
                <AlertDescription>
                    Repository, CI, review, and merge proof remain missing.
                </AlertDescription>
            </Alert>
        </div>
    );
}

function ExecutionStateAlert({
    inspector,
}: {
    inspector: DevelopmentInspector;
}) {
    if (inspector.execution.status === 'failed') {
        return (
            <Alert variant="destructive">
                <Ban aria-hidden="true" />
                <AlertTitle>Execution failed</AlertTitle>
                <AlertDescription>
                    {inspector.error?.message ??
                        'The safe failure summary is unavailable.'}
                </AlertDescription>
            </Alert>
        );
    }

    if (inspector.execution.status === 'retry_scheduled') {
        return (
            <Alert>
                <RefreshCw aria-hidden="true" />
                <AlertTitle>Retry scheduled</AlertTitle>
                <AlertDescription>
                    Next attempt{' '}
                    {formatOptionalDate(inspector.retry.nextAttemptAt)}.
                </AlertDescription>
            </Alert>
        );
    }

    if (inspector.execution.status === 'cancelled') {
        return (
            <Alert variant="destructive">
                <Ban aria-hidden="true" />
                <AlertTitle>Execution cancelled</AlertTitle>
                <AlertDescription>
                    {inspector.execution.cancellationReason ??
                        'Cancellation reason unavailable.'}
                </AlertDescription>
            </Alert>
        );
    }

    if (inspector.execution.terminal) {
        return (
            <Alert>
                <CheckCircle2 aria-hidden="true" />
                <AlertTitle>Terminal execution</AlertTitle>
                <AlertDescription>
                    This execution no longer polls for lifecycle updates.
                </AlertDescription>
            </Alert>
        );
    }

    return null;
}

function AttemptCard({ attempt }: { attempt: Attempt }) {
    return (
        <Card>
            <CardHeader className="flex-row items-center justify-between gap-3">
                <CardTitle className="text-base">
                    Attempt {attempt.number}
                </CardTitle>
                <Badge variant={attempt.error ? 'destructive' : 'secondary'}>
                    {humanize(attempt.status)}
                </Badge>
            </CardHeader>
            <CardContent className="grid gap-2 text-sm sm:grid-cols-2">
                <Detail label="Provider" value={attempt.provider} />
                <Detail
                    label="Actual state"
                    value={humanize(attempt.actualState ?? 'unverified')}
                />
                <Detail
                    label="Requested reasoning"
                    value={humanize(attempt.requestedReasoning)}
                />
                <Detail
                    label="Effective reasoning"
                    value={humanize(attempt.effectiveReasoning)}
                />
                <Detail
                    label="Reasoning source"
                    value={humanize(attempt.reasoningSource)}
                />
                <Detail
                    label="Simulation seed"
                    value={attempt.simulationSeed ?? 'Not recorded'}
                />
                {attempt.error && (
                    <div className="rounded-md border border-destructive/40 bg-destructive/5 p-3 sm:col-span-2">
                        <p className="font-medium">{attempt.error.code}</p>
                        <p className="mt-1 text-muted-foreground">
                            {attempt.error.message ??
                                'Safe error detail unavailable.'}
                        </p>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function Timeline({ inspector }: { inspector: DevelopmentInspector }) {
    return (
        <section
            aria-labelledby="timeline-heading"
            className="flex flex-col gap-3"
        >
            <div>
                <h2 id="timeline-heading" className="text-lg font-semibold">
                    Stage and lifecycle timeline
                </h2>
                <p className="text-sm text-muted-foreground">
                    Stable authoritative event ordering.
                </p>
            </div>
            {inspector.auditTimeline.length === 0 ? (
                <EmptyCard text="No development lifecycle event was recorded." />
            ) : (
                <ol className="flex flex-col gap-2">
                    {inspector.auditTimeline.map((event) => (
                        <li
                            key={event.sequence}
                            className="flex items-start gap-3 rounded-lg border bg-card p-4 text-sm"
                        >
                            <Clock3
                                className="mt-0.5 size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <div>
                                <p className="font-medium">
                                    {humanize(event.type)}
                                </p>
                                <p className="text-muted-foreground">
                                    {formatDate(event.occurredAt)}
                                    {event.attemptId
                                        ? ` · attempt ${event.attemptId}`
                                        : ''}
                                </p>
                            </div>
                        </li>
                    ))}
                </ol>
            )}
        </section>
    );
}

function ChangedFiles({
    files,
    diffSummary,
}: {
    files: DevelopmentInspector['changedFiles'];
    diffSummary: string | null;
}) {
    return (
        <section
            aria-labelledby="changed-files-heading"
            className="flex flex-col gap-3"
        >
            <div>
                <h2
                    id="changed-files-heading"
                    className="text-lg font-semibold"
                >
                    Changed files
                </h2>
                <p className="text-sm text-muted-foreground">
                    Synthetic manifest only; no workspace file changed.
                </p>
                {diffSummary && <p className="mt-2 text-sm">{diffSummary}</p>}
            </div>
            {files.length === 0 ? (
                <EmptyCard text="No changed-file artifact was recorded." />
            ) : (
                files.map((file) => (
                    <Card key={file.path}>
                        <CardContent className="flex gap-3 py-5 text-sm">
                            <FileCode2
                                className="size-5 shrink-0"
                                aria-hidden="true"
                            />
                            <div>
                                <p className="font-mono font-medium break-all">
                                    {file.path}
                                </p>
                                <p className="text-muted-foreground">
                                    {humanize(file.change_type)} ·{' '}
                                    {file.summary}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                ))
            )}
        </section>
    );
}

function Validation({
    validations,
}: {
    validations: DevelopmentInspector['validations'];
}) {
    return (
        <section
            aria-labelledby="validation-heading"
            className="flex flex-col gap-3"
        >
            <div>
                <h2 id="validation-heading" className="text-lg font-semibold">
                    Validation
                </h2>
                <p className="text-sm text-muted-foreground">
                    Simulated commands and outcomes, never raw command output.
                </p>
            </div>
            {validations.length === 0 ? (
                <EmptyCard text="No validation artifact was recorded." />
            ) : (
                validations.map((validation) => (
                    <Card key={`${validation.command}-${validation.status}`}>
                        <CardContent className="flex items-start justify-between gap-4 py-5 text-sm">
                            <div>
                                <p className="font-mono font-medium">
                                    {validation.command}
                                </p>
                                <p className="mt-1 text-muted-foreground">
                                    {validation.summary}
                                </p>
                            </div>
                            <Badge
                                variant={
                                    validation.status === 'passed'
                                        ? 'secondary'
                                        : 'destructive'
                                }
                            >
                                {humanize(validation.status)}
                            </Badge>
                        </CardContent>
                    </Card>
                ))
            )}
        </section>
    );
}

function RepositoryArtifacts({
    repository,
}: {
    repository: DevelopmentInspector['repository'];
}) {
    const artifacts = [
        ['Branch', repository.branch, GitBranch],
        ['Commit', repository.commit, GitCommitHorizontal],
        ['Push', repository.push, RefreshCw],
        ['Pull request', repository.pullRequest, GitPullRequestArrow],
    ] as const;

    return (
        <section
            aria-labelledby="repository-heading"
            className="flex flex-col gap-3"
        >
            <div>
                <h2 id="repository-heading" className="text-lg font-semibold">
                    Synthetic repository artifacts
                </h2>
                <p className="text-sm text-muted-foreground">
                    Every reference remains under the simulation namespace.
                </p>
            </div>
            <div className="grid gap-4 md:grid-cols-2">
                {artifacts.map(([label, artifact, Icon]) => (
                    <Card key={label}>
                        <CardHeader className="flex-row items-center gap-3">
                            <Icon className="size-5" aria-hidden="true" />
                            <CardTitle className="text-base">{label}</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm">
                            {artifact ? (
                                <>
                                    <p className="font-mono break-all">
                                        {artifact.name}
                                    </p>
                                    <p className="mt-2 break-all text-muted-foreground">
                                        {artifact.reference}
                                    </p>
                                    {artifact.target_branch && (
                                        <Badge
                                            className="mt-3"
                                            variant="outline"
                                        >
                                            Target {artifact.target_branch}
                                        </Badge>
                                    )}
                                </>
                            ) : (
                                <p className="text-muted-foreground">
                                    Artifact not recorded.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                ))}
            </div>
        </section>
    );
}

function Evidence({ inspector }: { inspector: DevelopmentInspector }) {
    const evidence = inspector.artifacts.flatMap(
        (artifact) => artifact.evidence,
    );

    return (
        <section
            aria-labelledby="evidence-heading"
            className="grid gap-4 lg:grid-cols-2"
        >
            <Card>
                <CardHeader>
                    <CardTitle>
                        <h2 id="evidence-heading">Evidence and gaps</h2>
                    </CardTitle>
                </CardHeader>
                <CardContent className="flex flex-col gap-3 text-sm">
                    {evidence.length === 0 ? (
                        <p className="text-muted-foreground">
                            No simulated evidence record was found.
                        </p>
                    ) : (
                        evidence.map((item) => (
                            <div
                                key={item.id}
                                className="rounded-md border p-3"
                            >
                                <div className="flex flex-wrap gap-2">
                                    <Badge variant="outline">
                                        {humanize(item.classification)}
                                    </Badge>
                                    <Badge
                                        variant={
                                            item.verified
                                                ? 'default'
                                                : 'destructive'
                                        }
                                    >
                                        {item.verified
                                            ? 'Verified'
                                            : 'Unverified'}
                                    </Badge>
                                </div>
                                <p className="mt-2 text-muted-foreground">
                                    {item.claims.join(' ')}
                                </p>
                            </div>
                        ))
                    )}
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>Remaining evidence gaps</CardTitle>
                </CardHeader>
                <CardContent>
                    <ul className="flex list-disc flex-col gap-2 pl-5 text-sm text-muted-foreground">
                        {inspector.assumptions.map((assumption) => (
                            <li key={assumption}>Assumption: {assumption}</li>
                        ))}
                        {inspector.risks.map((risk) => (
                            <li key={risk}>Risk: {risk}</li>
                        ))}
                        {inspector.confidence && (
                            <li>
                                Simulation confidence: {inspector.confidence}
                            </li>
                        )}
                        {inspector.evidenceGaps.map((gap) => (
                            <li key={gap}>{gap}</li>
                        ))}
                        {inspector.missingArtifacts.map((artifact) => (
                            <li key={artifact}>
                                Missing {humanize(artifact)} artifact.
                            </li>
                        ))}
                    </ul>
                </CardContent>
            </Card>
        </section>
    );
}

function LeaseAndRetry({ inspector }: { inspector: DevelopmentInspector }) {
    return (
        <section
            aria-labelledby="lease-retry-heading"
            className="grid gap-4 lg:grid-cols-2"
        >
            <Card>
                <CardHeader>
                    <CardTitle>
                        <h2 id="lease-retry-heading">Lease state</h2>
                    </CardTitle>
                </CardHeader>
                <CardContent className="flex flex-col gap-2 text-sm">
                    {inspector.lease ? (
                        <>
                            <Detail
                                label="Owner"
                                value={inspector.lease.owner}
                            />
                            <Detail
                                label="Recovery"
                                value={humanize(inspector.lease.recoveryState)}
                            />
                            <Detail
                                label="Expires"
                                value={formatDate(inspector.lease.expiresAt)}
                            />
                            {inspector.lease.expiredButExecutionLive && (
                                <Badge
                                    variant="destructive"
                                    className="self-start"
                                >
                                    Expired but execution is live
                                </Badge>
                            )}
                        </>
                    ) : (
                        <p className="text-muted-foreground">
                            Lease record is missing.
                        </p>
                    )}
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>Retry and error classification</CardTitle>
                </CardHeader>
                <CardContent className="flex flex-col gap-2 text-sm">
                    <Detail
                        label="Retry state"
                        value={
                            inspector.retry.scheduled
                                ? 'Scheduled'
                                : 'Not scheduled'
                        }
                    />
                    <Detail
                        label="Attempts"
                        value={`${inspector.retry.attemptCount} of ${inspector.retry.retryLimit + 1}`}
                    />
                    {inspector.error ? (
                        <>
                            <Detail
                                label="Error code"
                                value={inspector.error.code}
                            />
                            <Detail
                                label="Retryable"
                                value={inspector.error.retryable ? 'Yes' : 'No'}
                            />
                        </>
                    ) : (
                        <p className="text-muted-foreground">
                            No safe error classification was recorded.
                        </p>
                    )}
                </CardContent>
            </Card>
        </section>
    );
}

function TextListSection({
    id,
    title,
    values,
    empty,
}: {
    id: string;
    title: string;
    values: string[];
    empty: string;
}) {
    return (
        <section
            aria-labelledby={`${id}-heading`}
            className="flex flex-col gap-3"
        >
            <h2 id={`${id}-heading`} className="text-lg font-semibold">
                {title}
            </h2>
            {values.length === 0 ? (
                <EmptyCard text={empty} />
            ) : (
                <Card>
                    <CardContent className="py-5">
                        <ol className="flex list-decimal flex-col gap-2 pl-5 text-sm">
                            {values.map((value) => (
                                <li key={value}>{value}</li>
                            ))}
                        </ol>
                    </CardContent>
                </Card>
            )}
        </section>
    );
}

function Detail({
    label,
    value,
    mono = false,
}: {
    label: string;
    value: string;
    mono?: boolean;
}) {
    return (
        <div>
            <dt className="text-muted-foreground">{label}</dt>
            <dd
                className={mono ? 'font-mono text-xs break-all' : 'font-medium'}
            >
                {value}
            </dd>
        </div>
    );
}

function EmptyCard({ text }: { text: string }) {
    return (
        <Card>
            <CardContent className="py-6 text-sm text-muted-foreground">
                {text}
            </CardContent>
        </Card>
    );
}

function InspectorSkeleton() {
    return (
        <section
            aria-label="Loading execution inspector"
            aria-busy="true"
            className="grid gap-4 md:grid-cols-2"
        >
            {[0, 1, 2, 3].map((item) => (
                <Card key={item}>
                    <CardContent className="flex flex-col gap-3 py-6">
                        <Skeleton className="h-5 w-32" />
                        <Skeleton className="h-6 w-3/4" />
                        <Skeleton className="h-4 w-full" />
                    </CardContent>
                </Card>
            ))}
            <span className="sr-only">Loading execution inspector</span>
        </section>
    );
}

function DeferredRescue() {
    return (
        <Alert variant="destructive">
            <AlertTriangle aria-hidden="true" />
            <AlertTitle>Inspector data did not load</AlertTitle>
            <AlertDescription>
                The execution remains protected. Return to the queue or refresh
                this authorized read.
            </AlertDescription>
        </Alert>
    );
}

function humanize(value: string): string {
    return value
        .replaceAll('.', ' ')
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function formatOptionalDate(value: string | null): string {
    return value ? formatDate(value) : 'is pending a durable schedule';
}
