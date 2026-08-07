import { Head, Link, usePoll } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    ExternalLink,
    LayoutDashboard,
    Search,
    ShieldAlert,
} from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline';

export type ApprovalInboxItem = {
    id: string;
    category: 'roadmap' | 'conflict' | 'recovery' | 'merge';
    source: 'approval' | 'notion_conflict' | 'qa_assessment';
    type: string;
    title: string;
    summary: string;
    status: string;
    requestedAt: string;
    expiresAt: string | null;
    urgency: 'overdue' | 'expiring' | 'normal';
    overdue: boolean;
    simulated: boolean;
    actualState: string;
    contextUrl: string;
    contextLabel: string;
};

export type ApprovalInboxData = {
    metadata: {
        asOf: string;
        fingerprint: string;
    };
    summary: {
        total: number;
        roadmap: number;
        conflict: number;
        recovery: number;
        merge: number;
        overdue: number;
    };
    items: ApprovalInboxItem[];
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
    operationsUrl: string;
    inboxUrl: string;
    filters: {
        category: string;
        urgency: string;
        search: string;
        focusApproval: string | null;
    };
    canApprove: boolean;
    inbox: ApprovalInboxData;
};

const categories = [
    { value: 'all', label: 'All categories' },
    { value: 'roadmap', label: 'Roadmap approvals' },
    { value: 'conflict', label: 'Notion conflicts' },
    { value: 'recovery', label: 'Recovery decisions' },
    { value: 'merge', label: 'Merge decisions' },
];

const urgencies = [
    { value: 'all', label: 'All urgency levels' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'expiring', label: 'Expiring within 24 hours' },
    { value: 'normal', label: 'Normal' },
];

/**
 * Render the filterable project decision inbox.
 */
export default function ProjectApprovalInbox({
    organization,
    project,
    projectUrl,
    operationsUrl,
    inboxUrl,
    filters,
    canApprove,
    inbox,
}: Props) {
    usePoll(15_000, {
        only: ['inbox'],
    });

    return (
        <>
            <Head title={`${project.name} approval inbox`} />

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
                                Approval inbox
                            </h1>
                            <Badge variant="outline">
                                {humanize(project.status)}
                            </Badge>
                        </div>

                        <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                            Pending roadmap approvals, Notion conflicts,
                            recovery requests, and merge decisions for{' '}
                            {project.name}.
                        </p>
                    </div>

                    <Button asChild variant="outline">
                        <Link href={operationsUrl}>
                            <LayoutDashboard aria-hidden="true" />
                            Operational dashboard
                        </Link>
                    </Button>
                </header>

                <div
                    role="status"
                    aria-live="polite"
                    className="text-sm text-muted-foreground"
                >
                    Inbox updated {formatDate(inbox.metadata.asOf)}.{' '}
                    {inbox.items.length} matching item
                    {inbox.items.length === 1 ? '' : 's'}.
                </div>

                <FilterForm
                    inboxUrl={inboxUrl}
                    category={filters.category}
                    urgency={filters.urgency}
                    search={filters.search}
                />

                <ApprovalInboxContent
                    organizationName={organization.name}
                    inbox={inbox}
                    canApprove={canApprove}
                    focusedApproval={filters.focusApproval}
                />
            </main>
        </>
    );
}

/**
 * Render server-driven filters with a no-JavaScript compatible GET form.
 */
function FilterForm({
    inboxUrl,
    category,
    urgency,
    search,
}: {
    inboxUrl: string;
    category: string;
    urgency: string;
    search: string;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Filter decisions</CardTitle>
            </CardHeader>
            <CardContent>
                <form
                    action={inboxUrl}
                    method="get"
                    className="grid gap-4 md:grid-cols-[1fr_1fr_2fr_auto_auto] md:items-end"
                >
                    <label className="grid gap-2 text-sm font-medium">
                        Category
                        <select
                            name="category"
                            defaultValue={category}
                            className="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            {categories.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </label>

                    <label className="grid gap-2 text-sm font-medium">
                        Urgency
                        <select
                            name="urgency"
                            defaultValue={urgency}
                            className="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            {urgencies.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </label>

                    <label className="grid gap-2 text-sm font-medium">
                        Search
                        <span className="relative">
                            <Search
                                aria-hidden="true"
                                className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            />
                            <input
                                type="search"
                                name="search"
                                defaultValue={search}
                                maxLength={120}
                                placeholder="Ticket, decision, or conflict"
                                className="h-9 w-full rounded-md border border-input bg-background pr-3 pl-9 text-sm shadow-xs outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            />
                        </span>
                    </label>

                    <Button type="submit">Apply filters</Button>

                    <Button asChild type="button" variant="outline">
                        <Link href={inboxUrl}>Reset</Link>
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

/**
 * Render summary counters and normalized decision items.
 */
export function ApprovalInboxContent({
    organizationName,
    inbox,
    canApprove,
    focusedApproval,
}: {
    organizationName: string;
    inbox: ApprovalInboxData;
    canApprove: boolean;
    focusedApproval: string | null;
}) {
    const hasSimulatedItems = inbox.items.some((item) => item.simulated);

    return (
        <div className="space-y-6">
            {hasSimulatedItems && (
                <Alert>
                    <ShieldAlert aria-hidden="true" />
                    <AlertTitle>Simulated decisions are advisory</AlertTitle>
                    <AlertDescription>
                        A simulated merge recommendation updates internal
                        workflow state only. It cannot authorize a real merge
                        and its evidence remains unverified.
                    </AlertDescription>
                </Alert>
            )}

            <SummaryCards inbox={inbox} />

            <section aria-labelledby="approval-items-heading">
                <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2
                        id="approval-items-heading"
                        className="text-lg font-semibold"
                    >
                        Pending decisions
                    </h2>
                    <Badge variant="outline">
                        {inbox.items.length} visible
                    </Badge>
                </div>

                {inbox.items.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-3 py-12 text-center">
                            <CheckCircle2
                                className="size-8 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <p className="font-medium">
                                No decisions match these filters
                            </p>
                            <p className="max-w-lg text-sm text-muted-foreground">
                                The project may be clear, or another category or
                                urgency filter may contain pending work.
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <ul className="space-y-4">
                        {inbox.items.map((item) => (
                            <li key={`${item.source}-${item.id}`}>
                                <ApprovalItemCard
                                    item={item}
                                    canApprove={canApprove}
                                    focused={
                                        item.source === 'approval' &&
                                        item.id === focusedApproval
                                    }
                                />
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <p className="text-xs text-muted-foreground">
                Organization: {organizationName}. Inbox fingerprint{' '}
                {inbox.metadata.fingerprint.slice(0, 12)}…
            </p>
        </div>
    );
}

/**
 * Render one decision with explicit source, urgency, provenance, and action.
 */
function ApprovalItemCard({
    item,
    canApprove,
    focused,
}: {
    item: ApprovalInboxItem;
    canApprove: boolean;
    focused: boolean;
}) {
    return (
        <article
            id={
                item.source === 'approval'
                    ? `approval-${item.id}`
                    : `${item.source}-${item.id}`
            }
            className={`rounded-xl border bg-card p-5 shadow-sm ${
                focused ? 'ring-2 ring-ring ring-offset-2' : ''
            }`}
        >
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <CategoryBadge category={item.category} />
                        <StatusBadge value={item.status} />
                        {item.urgency !== 'normal' && (
                            <Badge
                                variant={
                                    item.urgency === 'overdue'
                                        ? 'destructive'
                                        : 'secondary'
                                }
                            >
                                {humanize(item.urgency)}
                            </Badge>
                        )}
                        {item.simulated && (
                            <Badge variant="secondary">Simulated</Badge>
                        )}
                    </div>

                    <h3 className="mt-3 text-base font-semibold">
                        {item.title}
                    </h3>
                    <p className="mt-2 max-w-4xl text-sm leading-6 text-muted-foreground">
                        {item.summary}
                    </p>

                    <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                        <Detail label="Type" value={humanize(item.type)} />
                        <Detail
                            label="Requested"
                            value={formatDate(item.requestedAt)}
                        />
                        <Detail
                            label="Expires"
                            value={
                                item.expiresAt
                                    ? formatDate(item.expiresAt)
                                    : 'No expiry'
                            }
                        />
                        <Detail
                            label="Actual state"
                            value={humanize(item.actualState)}
                        />
                    </dl>
                </div>

                <Button asChild>
                    <Link href={item.contextUrl}>
                        <ExternalLink aria-hidden="true" />
                        {canApprove
                            ? item.contextLabel
                            : 'View decision context'}
                    </Link>
                </Button>
            </div>

            {item.overdue && (
                <p className="mt-4 flex items-center gap-2 text-sm font-medium text-destructive">
                    <AlertTriangle aria-hidden="true" className="size-4" />
                    This pending approval has passed its recorded expiry time.
                </p>
            )}
        </article>
    );
}

/**
 * Render compact category and overdue totals.
 */
function SummaryCards({ inbox }: { inbox: ApprovalInboxData }) {
    const cards = [
        ['All pending', inbox.summary.total],
        ['Roadmaps', inbox.summary.roadmap],
        ['Conflicts', inbox.summary.conflict],
        ['Recovery', inbox.summary.recovery],
        ['Merge decisions', inbox.summary.merge],
        ['Overdue', inbox.summary.overdue],
    ] as const;

    return (
        <section aria-labelledby="approval-summary-heading">
            <h2 id="approval-summary-heading" className="sr-only">
                Approval inbox summary
            </h2>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
                {cards.map(([label, value]) => (
                    <Card key={label}>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                {label}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-3xl font-semibold tabular-nums">
                                {value}
                            </p>
                        </CardContent>
                    </Card>
                ))}
            </div>
        </section>
    );
}

/**
 * Render one label/value pair with semantic description-list markup.
 */
function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="mt-1 font-medium">{value}</dd>
        </div>
    );
}

/**
 * Render the stable decision category.
 */
function CategoryBadge({
    category,
}: {
    category: ApprovalInboxItem['category'];
}) {
    return <Badge variant="outline">{humanize(category)}</Badge>;
}

/**
 * Render a display-only status badge.
 */
function StatusBadge({ value }: { value: string }) {
    return <Badge variant={statusVariant(value)}>{humanize(value)}</Badge>;
}

/**
 * Map display severity to an existing badge variant.
 */
function statusVariant(value: string): BadgeVariant {
    const normalized = value.toLowerCase();

    if (
        normalized.includes('blocked') ||
        normalized.includes('failed') ||
        normalized.includes('reject') ||
        normalized.includes('conflict')
    ) {
        return 'destructive';
    }

    if (
        normalized.includes('pending') ||
        normalized.includes('waiting') ||
        normalized.includes('unverified')
    ) {
        return 'secondary';
    }

    if (
        normalized.includes('approved') ||
        normalized.includes('ready') ||
        normalized.includes('completed')
    ) {
        return 'default';
    }

    return 'outline';
}

/**
 * Convert enum-like values into human-readable labels.
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
