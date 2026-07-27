import { Deferred, Form, Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    GitBranch,
    RefreshCw,
    Route,
} from 'lucide-react';
import type { ReactNode } from 'react';
import {
    approve,
    reject,
} from '@/actions/App/Http/Controllers/Planning/DecideRoadmapController';
import EditRoadmapController from '@/actions/App/Http/Controllers/Planning/EditRoadmapController';
import RegenerateRoadmapController from '@/actions/App/Http/Controllers/Planning/RegenerateRoadmapController';
import {
    phase,
    show,
    task,
} from '@/actions/App/Http/Controllers/Planning/RoadmapController';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import type { RoadmapTask, RoadmapView } from '@/types';

type Props = {
    organization: { id: number; name: string; slug: string };
    project: { id: number; name: string; slug: string; status: string };
    projectUrl: string;
    revisions: Array<{
        id: number;
        revision: number;
        contentVersion: number;
        status: string;
        readiness: string;
        generatedAt: string | null;
    }>;
    roadmap: RoadmapView | null;
    diagnostics?: Array<{
        code: string;
        category: string;
        message: string;
        createdAt: string | null;
    }>;
    selectedPhaseId: number | null;
    selectedTaskId: number | null;
    permissions: { edit: boolean; decide: boolean; regenerate: boolean };
    actionIdempotencyKey: string;
};

export default function RoadmapShow(props: Props) {
    const { organization, project, roadmap } = props;
    const routeArgs = { organization, project, roadmap: roadmap?.id ?? 0 };
    const selectedTask = roadmap?.tasks.find(
        (candidate) => candidate.id === props.selectedTaskId,
    );

    return (
        <>
            <Head title={`Roadmap · ${project.name}`} />
            <main className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <Button asChild variant="ghost" size="sm">
                            <Link href={props.projectUrl}>
                                <ArrowLeft aria-hidden="true" />
                                Back to project
                            </Link>
                        </Button>
                        <div className="mt-4 flex flex-wrap items-center gap-3">
                            <h1 className="text-2xl font-semibold tracking-tight">
                                Roadmap inspection
                            </h1>
                            {roadmap && (
                                <>
                                    <Badge variant="outline">
                                        Revision {roadmap.revision}
                                    </Badge>
                                    <Badge>{humanize(roadmap.status)}</Badge>
                                </>
                            )}
                        </div>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Sources, dependencies, risks, reasoning, and
                            approval history for {project.name}.
                        </p>
                    </div>
                    {roadmap && (
                        <nav
                            aria-label="Roadmap revisions"
                            className="flex flex-wrap gap-2"
                        >
                            {props.revisions.map((revision) => (
                                <Button
                                    key={revision.id}
                                    asChild
                                    size="sm"
                                    variant={
                                        revision.id === roadmap.id
                                            ? 'default'
                                            : 'outline'
                                    }
                                >
                                    <Link
                                        href={show({
                                            organization,
                                            project,
                                            roadmap: revision.id,
                                        })}
                                    >
                                        v{revision.revision}.
                                        {revision.contentVersion}
                                    </Link>
                                </Button>
                            ))}
                        </nav>
                    )}
                </header>

                <Deferred data="diagnostics" fallback={<DiagnosticsSkeleton />}>
                    <Diagnostics diagnostics={props.diagnostics ?? []} />
                </Deferred>

                {!roadmap ? (
                    <div className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    <h2>No roadmap yet</h2>
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="text-sm text-muted-foreground">
                                {(props.diagnostics?.length ?? 0) > 0
                                    ? 'Resolve the blocking planning diagnostic, then regenerate the roadmap.'
                                    : 'Start project planning to create an inspectable roadmap.'}
                            </CardContent>
                        </Card>
                    </div>
                ) : (
                    <>
                        {!roadmap.isLatest && (
                            <Alert variant="destructive">
                                <AlertTriangle aria-hidden="true" />
                                <AlertTitle>Stale roadmap revision</AlertTitle>
                                <AlertDescription>
                                    This historical revision is read-only.
                                    Select the latest revision to make a
                                    decision.
                                </AlertDescription>
                            </Alert>
                        )}
                        <Readiness roadmap={roadmap} />
                        <section className="grid gap-6 xl:grid-cols-[18rem_minmax(0,1fr)]">
                            <aside className="space-y-4">
                                <Card>
                                    <CardHeader>
                                        <CardTitle>
                                            <h2>Phases</h2>
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <nav
                                            aria-label="Roadmap phases"
                                            className="space-y-2"
                                        >
                                            {roadmap.phases.map((item) => (
                                                <Link
                                                    key={item.id}
                                                    href={phase({
                                                        ...routeArgs,
                                                        phase: item.id,
                                                    })}
                                                    className="block rounded-md border p-3 text-sm hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                >
                                                    <span className="font-medium">
                                                        {item.name}
                                                    </span>
                                                    <span className="mt-1 block text-xs text-muted-foreground">
                                                        {item.milestones.length}{' '}
                                                        milestone(s)
                                                    </span>
                                                </Link>
                                            ))}
                                        </nav>
                                    </CardContent>
                                </Card>
                                <TraceabilityMatrix roadmap={roadmap} />
                            </aside>

                            <div className="space-y-6">
                                <RoadmapSummary roadmap={roadmap} />
                                <TaskList
                                    roadmap={roadmap}
                                    routeArgs={routeArgs}
                                    selectedPhaseId={props.selectedPhaseId}
                                />
                                {selectedTask && (
                                    <TaskInspector task={selectedTask} />
                                )}
                                <RoadmapActions
                                    {...props}
                                    routeArgs={routeArgs}
                                />
                            </div>
                        </section>
                    </>
                )}
            </main>
        </>
    );
}

function Readiness({ roadmap }: { roadmap: RoadmapView }) {
    const blocked = roadmap.readiness === 'blocked';

    return (
        <Alert variant={blocked ? 'destructive' : 'default'}>
            {blocked ? (
                <AlertTriangle aria-hidden="true" />
            ) : (
                <CheckCircle2 aria-hidden="true" />
            )}
            <AlertTitle>{humanize(roadmap.readiness)}</AlertTitle>
            <AlertDescription>
                {roadmap.readinessReasons.length > 0
                    ? roadmap.readinessReasons.join(' · ')
                    : 'All deterministic roadmap readiness checks passed.'}
            </AlertDescription>
        </Alert>
    );
}

function RoadmapSummary({ roadmap }: { roadmap: RoadmapView }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>
                    <h2>{roadmap.goal}</h2>
                </CardTitle>
            </CardHeader>
            <CardContent className="grid gap-5 md:grid-cols-2">
                <List title="Scope" values={roadmap.scope} />
                <List title="Constraints" values={roadmap.constraints} />
                <List
                    title="Assumptions"
                    values={roadmap.assumptions}
                    empty="No assumptions recorded."
                />
                <List
                    title="Definition of done"
                    values={roadmap.definitionOfDone}
                />
                <div className="md:col-span-2">
                    <h3 className="text-sm font-medium">Document analysis</h3>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {roadmap.documentSummary}
                    </p>
                </div>
                <List
                    title="Architecture concerns"
                    values={roadmap.architectureConcerns}
                    empty="No architecture concerns recorded."
                />
                <List
                    title="Security concerns"
                    values={roadmap.securityConcerns}
                    empty="No security concerns recorded."
                />
                <div className="md:col-span-2">
                    <h3 className="text-sm font-medium">
                        Approved document inventory
                    </h3>
                    {roadmap.documentInventory.length === 0 ? (
                        <p className="mt-2 text-sm text-muted-foreground">
                            No approved document versions were available.
                        </p>
                    ) : (
                        <ul className="mt-2 space-y-2 text-sm">
                            {roadmap.documentInventory.map((document) => (
                                <li
                                    key={document.document_version_id}
                                    className="rounded-md border p-3"
                                >
                                    Document {document.document_id}, version{' '}
                                    {document.version}
                                    <span className="block text-xs text-muted-foreground">
                                        {document.summary} ·{' '}
                                        {document.checksum_sha256.slice(0, 12)}…
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
                <div className="md:col-span-2">
                    <h3 className="text-sm font-medium">
                        Generated, candidate, and approved comparison
                    </h3>
                    <dl className="mt-2 grid gap-3 rounded-md border p-3 text-sm md:grid-cols-3">
                        <ComparisonValue
                            label="Generated"
                            value={roadmap.comparison.generatedGoal}
                            fingerprint={
                                roadmap.comparison.generatedFingerprint
                            }
                        />
                        <ComparisonValue
                            label="Current candidate"
                            value={roadmap.comparison.candidateGoal}
                            fingerprint={
                                roadmap.comparison.candidateFingerprint
                            }
                        />
                        <ComparisonValue
                            label="Approved"
                            value={roadmap.comparison.approvedGoal}
                            fingerprint={roadmap.comparison.approvedFingerprint}
                        />
                    </dl>
                </div>
            </CardContent>
        </Card>
    );
}

function ComparisonValue({
    label,
    value,
    fingerprint,
}: {
    label: string;
    value: string | null;
    fingerprint: string | null;
}) {
    return (
        <div>
            <dt className="font-medium">{label}</dt>
            <dd className="mt-1 text-muted-foreground">
                {value ?? 'Not available'}
            </dd>
            <dd className="mt-1 font-mono text-xs text-muted-foreground">
                {fingerprint
                    ? `${fingerprint.slice(0, 12)}…`
                    : 'No fingerprint'}
            </dd>
        </div>
    );
}

function Diagnostics({
    diagnostics,
}: {
    diagnostics: NonNullable<Props['diagnostics']>;
}) {
    return diagnostics.map((diagnostic) => (
        <Alert
            key={`${diagnostic.code}-${diagnostic.createdAt}`}
            variant="destructive"
        >
            <AlertTriangle aria-hidden="true" />
            <AlertTitle>Planning blocked: {diagnostic.code}</AlertTitle>
            <AlertDescription>{diagnostic.message}</AlertDescription>
        </Alert>
    ));
}

function DiagnosticsSkeleton() {
    return (
        <div
            role="status"
            aria-label="Loading planning diagnostics"
            className="h-20 animate-pulse rounded-lg bg-muted"
        >
            <span className="sr-only">Loading planning diagnostics</span>
        </div>
    );
}

function TaskList({
    roadmap,
    routeArgs,
    selectedPhaseId,
}: {
    roadmap: RoadmapView;
    routeArgs: {
        organization: Props['organization'];
        project: Props['project'];
        roadmap: number;
    };
    selectedPhaseId: number | null;
}) {
    const tasks =
        selectedPhaseId === null
            ? roadmap.tasks
            : roadmap.tasks.filter((item) => item.phaseId === selectedPhaseId);

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <GitBranch aria-hidden="true" />
                    <h2>Tasks and dependencies</h2>
                </CardTitle>
            </CardHeader>
            <CardContent>
                {tasks.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No tasks exist for this selection.
                    </p>
                ) : (
                    <ul className="grid gap-3 md:grid-cols-2">
                        {tasks.map((item) => (
                            <li key={item.id}>
                                <Link
                                    href={task({ ...routeArgs, task: item.id })}
                                    className="block h-full rounded-lg border p-4 hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">
                                            {item.title}
                                        </span>
                                        {item.isCriticalPath && (
                                            <Badge variant="destructive">
                                                Critical path #
                                                {item.criticalPathPosition}
                                            </Badge>
                                        )}
                                    </div>
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        {item.objective}
                                    </p>
                                    <div className="mt-3 flex flex-wrap gap-2 text-xs">
                                        <Badge variant="outline">
                                            {item.priority}
                                        </Badge>
                                        <Badge variant="outline">
                                            Risk: {item.risk}
                                        </Badge>
                                        <Badge variant="outline">
                                            Effort: {item.estimatedComplexity}
                                        </Badge>
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

function TaskInspector({ task: item }: { task: RoadmapTask }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>
                    <h2>{item.title}</h2>
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-5 text-sm">
                <p>{item.objective}</p>
                <div>
                    <h3 className="font-medium">Reasoning</h3>
                    <p className="mt-1 text-muted-foreground">
                        {item.reasoning}
                    </p>
                </div>
                <List
                    title="Acceptance criteria"
                    values={item.acceptanceCriteria.map(
                        (criterion) => criterion.description,
                    )}
                />
                <List
                    title="Required evidence"
                    values={item.evidenceRequirements}
                />
                <List
                    title="Dependencies"
                    values={item.dependencies.map(
                        (dependency) => dependency.title,
                    )}
                    empty="No dependencies."
                />
                <div>
                    <h3 className="font-medium">Source coverage</h3>
                    <ul className="mt-2 space-y-2">
                        {item.traceability.map((source) => (
                            <li
                                key={`${source.criterionStableId}-${source.documentVersionId}`}
                                className="rounded-md bg-muted p-3"
                            >
                                <span className="font-medium">
                                    {source.documentName} v
                                    {source.documentVersion}
                                </span>
                                <span className="mt-1 block text-xs text-muted-foreground">
                                    {source.criterionStableId} ·{' '}
                                    {source.checksumSha256.slice(0, 12)}…
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            </CardContent>
        </Card>
    );
}

function TraceabilityMatrix({ roadmap }: { roadmap: RoadmapView }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Route aria-hidden="true" />
                    <h2>Traceability</h2>
                </CardTitle>
            </CardHeader>
            <CardContent>
                {roadmap.reverseTraceability.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No source coverage is available.
                    </p>
                ) : (
                    <ul className="space-y-3">
                        {roadmap.reverseTraceability.map((source) => (
                            <li
                                key={source.documentVersionId}
                                className="rounded-md border p-3 text-sm"
                            >
                                <span className="font-medium">
                                    {source.documentName} v
                                    {source.documentVersion}
                                </span>
                                <span className="mt-1 block text-xs text-muted-foreground">
                                    Covers {source.tasks.length} criterion
                                    link(s)
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

function RoadmapActions(
    props: Props & {
        routeArgs: {
            organization: Props['organization'];
            project: Props['project'];
            roadmap: number;
        };
    },
) {
    const roadmap = props.roadmap;

    if (
        !roadmap ||
        !roadmap.isLatest ||
        roadmap.status !== 'awaiting_approval'
    ) {
        return null;
    }

    if (
        !props.permissions.edit &&
        !props.permissions.decide &&
        !props.permissions.regenerate
    ) {
        return (
            <Alert>
                <AlertTriangle aria-hidden="true" />
                <AlertTitle>Read-only roadmap access</AlertTitle>
                <AlertDescription>
                    You can inspect this roadmap, but you are not authorized to
                    edit it or submit an approval decision.
                </AlertDescription>
            </Alert>
        );
    }

    const common = {
        expected_content_version: roadmap.contentVersion,
        expected_fingerprint: roadmap.candidateFingerprint,
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>
                    <h2>Review and feedback</h2>
                </CardTitle>
            </CardHeader>
            <CardContent className="grid gap-5 lg:grid-cols-2">
                {props.permissions.edit && (
                    <Form
                        {...EditRoadmapController.form(props.routeArgs)}
                        className="space-y-3"
                    >
                        {({ processing, errors }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="expected_content_version"
                                    value={common.expected_content_version}
                                />
                                <input
                                    type="hidden"
                                    name="expected_fingerprint"
                                    value={common.expected_fingerprint}
                                />
                                <input
                                    type="hidden"
                                    name="idempotency_key"
                                    value={`roadmap-edit:${props.actionIdempotencyKey}`}
                                />
                                <label
                                    htmlFor="roadmap-goal"
                                    className="text-sm font-medium"
                                >
                                    Edit roadmap goal
                                </label>
                                <Textarea
                                    id="roadmap-goal"
                                    name="patch[roadmap][goal]"
                                    defaultValue={roadmap.goal}
                                    required
                                />
                                <ActionErrors errors={errors} />
                                <SubmitButton
                                    label="Save edit"
                                    processing={processing}
                                />
                            </>
                        )}
                    </Form>
                )}
                {props.permissions.decide && (
                    <div className="space-y-4">
                        <Form {...approve.form(props.routeArgs)}>
                            {({ processing, errors }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="expected_content_version"
                                        value={common.expected_content_version}
                                    />
                                    <input
                                        type="hidden"
                                        name="expected_fingerprint"
                                        value={common.expected_fingerprint}
                                    />
                                    <input
                                        type="hidden"
                                        name="idempotency_key"
                                        value={`roadmap-approve:${props.actionIdempotencyKey}`}
                                    />
                                    <ActionErrors errors={errors} />
                                    <SubmitButton
                                        label="Approve roadmap"
                                        processing={processing}
                                    />
                                </>
                            )}
                        </Form>
                        <Form
                            {...reject.form(props.routeArgs)}
                            className="space-y-3"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="expected_content_version"
                                        value={common.expected_content_version}
                                    />
                                    <input
                                        type="hidden"
                                        name="expected_fingerprint"
                                        value={common.expected_fingerprint}
                                    />
                                    <input
                                        type="hidden"
                                        name="idempotency_key"
                                        value={`roadmap-reject:${props.actionIdempotencyKey}`}
                                    />
                                    <label
                                        htmlFor="rejection-reason"
                                        className="text-sm font-medium"
                                    >
                                        Rejection feedback
                                    </label>
                                    <Textarea
                                        id="rejection-reason"
                                        name="reason"
                                        required
                                    />
                                    <ActionErrors errors={errors} />
                                    <SubmitButton
                                        label="Reject roadmap"
                                        destructive
                                        processing={processing}
                                    />
                                </>
                            )}
                        </Form>
                    </div>
                )}
                {props.permissions.regenerate && (
                    <Form
                        {...RegenerateRoadmapController.form(props.routeArgs)}
                        className="space-y-3 lg:col-span-2"
                    >
                        {({ processing, errors }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="expected_content_version"
                                    value={common.expected_content_version}
                                />
                                <input
                                    type="hidden"
                                    name="expected_fingerprint"
                                    value={common.expected_fingerprint}
                                />
                                <input
                                    type="hidden"
                                    name="idempotency_key"
                                    value={`roadmap-regenerate:${props.actionIdempotencyKey}`}
                                />
                                <label
                                    htmlFor="regeneration-feedback"
                                    className="text-sm font-medium"
                                >
                                    Regeneration feedback
                                </label>
                                <Textarea
                                    id="regeneration-feedback"
                                    name="feedback"
                                    required
                                />
                                <ActionErrors errors={errors} />
                                <SubmitButton
                                    label="Regenerate roadmap"
                                    icon={<RefreshCw aria-hidden="true" />}
                                    processing={processing}
                                />
                            </>
                        )}
                    </Form>
                )}
            </CardContent>
        </Card>
    );
}

function SubmitButton({
    label,
    destructive = false,
    icon,
    processing = false,
}: {
    label: string;
    destructive?: boolean;
    icon?: ReactNode;
    processing?: boolean;
}) {
    return (
        <Button
            type="submit"
            variant={destructive ? 'destructive' : 'default'}
            disabled={processing}
        >
            {icon}
            {processing ? 'Working…' : label}
        </Button>
    );
}

function ActionErrors({ errors }: { errors: Partial<Record<string, string>> }) {
    const messages = [...new Set(Object.values(errors).filter(Boolean))];

    if (messages.length === 0) {
        return null;
    }

    return (
        <Alert variant="destructive" role="alert">
            <AlertTitle>Action could not be completed</AlertTitle>
            <AlertDescription>{messages.join(' · ')}</AlertDescription>
        </Alert>
    );
}

function List({
    title,
    values,
    empty = 'None recorded.',
}: {
    title: string;
    values: string[];
    empty?: string;
}) {
    return (
        <div>
            <h3 className="text-sm font-medium">{title}</h3>
            {values.length === 0 ? (
                <p className="mt-2 text-sm text-muted-foreground">{empty}</p>
            ) : (
                <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                    {values.map((value) => (
                        <li key={value}>{value}</li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function humanize(value: string) {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}
