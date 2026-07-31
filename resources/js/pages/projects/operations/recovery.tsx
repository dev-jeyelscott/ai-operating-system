import { Head, Link, router, usePoll } from '@inertiajs/react';
import { ArrowLeft, Clock3, RotateCcw, ShieldAlert } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type RecoveryExecution = {
    id: string;
    kind: 'blocked' | 'retry' | 'failed';
    status: string;
    capability: string;
    logicalRole: string | null;
    provider: string | null;
    attemptCount: number;
    retryLimit: number;
    nextAttemptAt: string | null;
    errorCode: string | null;
    errorMessage: string | null;
    retryable: boolean | null;
    finishedAt: string | null;
    simulated: boolean;
    recommendedAction: string;
    contextUrl: string;
};

type DeadLetter = {
    source: 'outbox' | 'queue';
    id: string;
    eventId: string;
    eventName: string;
    attempts: number;
    failedAt: string;
    errorType: string;
};

type RecoveryData = {
    metadata: {
        asOf: string;
        fingerprint: string;
    };
    summary: {
        blocked: number;
        retryScheduled: number;
        failed: number;
        deadLetters: number;
    };
    executions: RecoveryExecution[];
    deadLetters: DeadLetter[];
};

type Props = {
    project: {
        id: number;
        name: string;
        slug: string;
    };
    operationsUrl: string;
    usageUrl: string;
    replayUrl: string;
    canReplay: boolean;
    recovery: RecoveryData;
    errors?: {
        replay?: string;
    };
};

/**
 * Render project-scoped failure and recovery information.
 */
export default function ProjectRecoveryCenter({
    project,
    operationsUrl,
    usageUrl,
    replayUrl,
    canReplay,
    recovery,
    errors = {},
}: Props) {
    const [refreshing, setRefreshing] = useState(false);
    const [reasons, setReasons] = useState<Record<string, string>>({});
    const [replaying, setReplaying] = useState<string | null>(null);

    usePoll(10_000, {
        only: ['recovery'],
        preserveScroll: true,
        preserveState: true,
        onStart: () => setRefreshing(true),
        onFinish: () => setRefreshing(false),
    });

    /**
     * Submit one audited and project-scoped dead-letter replay.
     */
    function replayDeadLetter(
        event: FormEvent<HTMLFormElement>,
        deadLetter: DeadLetter,
    ): void {
        event.preventDefault();

        const key = `${deadLetter.source}:${deadLetter.id}`;
        const reason = reasons[key]?.trim() ?? '';

        if (reason.length < 10) {
            return;
        }

        setReplaying(key);

        router.post(
            replayUrl,
            {
                source: deadLetter.source,
                identifier: deadLetter.id,
                reason,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setReasons((current) => ({
                        ...current,
                        [key]: '',
                    }));
                },
                onFinish: () => setReplaying(null),
            },
        );
    }

    return (
        <>
            <Head title={`${project.name} recovery center`} />

            <main className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <Button asChild variant="ghost" size="sm">
                            <Link href={operationsUrl}>
                                <ArrowLeft aria-hidden="true" />
                                Back to operations
                            </Link>
                        </Button>

                        <h1 className="mt-4 text-2xl font-semibold tracking-tight">
                            Blocker and recovery center
                        </h1>

                        <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                            Inspect project failures, scheduled retries,
                            blockers, and allowlisted dead letters. Execution
                            retries remain controlled by deterministic workflow
                            policy.
                        </p>
                    </div>

                    <Button asChild variant="outline">
                        <Link href={usageUrl}>Usage & costs</Link>
                    </Button>
                </header>

                <div
                    role="status"
                    aria-live="polite"
                    className="text-sm text-muted-foreground"
                >
                    {refreshing
                        ? 'Refreshing recovery state.'
                        : `Recovery state updated ${formatDate(recovery.metadata.asOf)}.`}
                </div>

                {errors.replay && (
                    <Alert variant="destructive">
                        <ShieldAlert aria-hidden="true" />
                        <AlertTitle>Replay was not accepted</AlertTitle>
                        <AlertDescription>{errors.replay}</AlertDescription>
                    </Alert>
                )}

                <section
                    aria-label="Recovery summary"
                    className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                >
                    <SummaryCard
                        label="Blocked"
                        value={recovery.summary.blocked}
                    />
                    <SummaryCard
                        label="Scheduled retries"
                        value={recovery.summary.retryScheduled}
                    />
                    <SummaryCard
                        label="Terminal failures"
                        value={recovery.summary.failed}
                    />
                    <SummaryCard
                        label="Dead letters"
                        value={recovery.summary.deadLetters}
                    />
                </section>

                <section aria-labelledby="execution-recovery-heading">
                    <Card>
                        <CardHeader>
                            <CardTitle id="execution-recovery-heading">
                                Execution recovery state
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {recovery.executions.length === 0 ? (
                                <EmptyState message="No blocked, retrying, or failed executions." />
                            ) : (
                                <ul className="space-y-4">
                                    {recovery.executions.map((execution) => (
                                        <li
                                            key={execution.id}
                                            className="rounded-lg border p-4"
                                        >
                                            <div className="flex flex-wrap items-start justify-between gap-4">
                                                <div className="space-y-2">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <Badge
                                                            variant={badgeVariant(
                                                                execution.kind,
                                                            )}
                                                        >
                                                            {humanize(
                                                                execution.status,
                                                            )}
                                                        </Badge>

                                                        {execution.simulated && (
                                                            <Badge variant="secondary">
                                                                Simulated
                                                            </Badge>
                                                        )}
                                                    </div>

                                                    <p className="font-medium">
                                                        {humanize(
                                                            execution.logicalRole ??
                                                                execution.capability,
                                                        )}
                                                    </p>

                                                    <p className="text-sm text-muted-foreground">
                                                        Attempt{' '}
                                                        {execution.attemptCount}{' '}
                                                        of{' '}
                                                        {execution.retryLimit +
                                                            1}
                                                        {execution.nextAttemptAt
                                                            ? ` · next retry ${formatDate(execution.nextAttemptAt)}`
                                                            : ''}
                                                    </p>

                                                    {execution.errorCode && (
                                                        <p className="text-sm">
                                                            <span className="font-medium">
                                                                Error:
                                                            </span>{' '}
                                                            {
                                                                execution.errorCode
                                                            }
                                                        </p>
                                                    )}

                                                    {execution.errorMessage && (
                                                        <p className="max-w-3xl text-sm text-muted-foreground">
                                                            {
                                                                execution.errorMessage
                                                            }
                                                        </p>
                                                    )}

                                                    <p className="max-w-3xl text-sm text-muted-foreground">
                                                        {
                                                            execution.recommendedAction
                                                        }
                                                    </p>
                                                </div>

                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="outline"
                                                >
                                                    <Link
                                                        href={
                                                            execution.contextUrl
                                                        }
                                                    >
                                                        Inspect execution
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

                <section aria-labelledby="dead-letters-heading">
                    <Card>
                        <CardHeader>
                            <CardTitle id="dead-letters-heading">
                                Project dead letters
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {recovery.deadLetters.length === 0 ? (
                                <EmptyState message="No project dead letters." />
                            ) : (
                                <ul className="space-y-4">
                                    {recovery.deadLetters.map((deadLetter) => {
                                        const key = `${deadLetter.source}:${deadLetter.id}`;
                                        const reason = reasons[key] ?? '';
                                        const isReplaying = replaying === key;

                                        return (
                                            <li
                                                key={key}
                                                className="rounded-lg border p-4"
                                            >
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <Badge variant="destructive">
                                                        {humanize(
                                                            deadLetter.source,
                                                        )}
                                                    </Badge>
                                                    <Badge variant="outline">
                                                        {deadLetter.eventName}
                                                    </Badge>
                                                </div>

                                                <dl className="mt-3 grid gap-2 text-sm md:grid-cols-2">
                                                    <div>
                                                        <dt className="font-medium">
                                                            Event
                                                        </dt>
                                                        <dd className="break-all text-muted-foreground">
                                                            {deadLetter.eventId}
                                                        </dd>
                                                    </div>
                                                    <div>
                                                        <dt className="font-medium">
                                                            Failed
                                                        </dt>
                                                        <dd className="text-muted-foreground">
                                                            {formatDate(
                                                                deadLetter.failedAt,
                                                            )}
                                                        </dd>
                                                    </div>
                                                    <div>
                                                        <dt className="font-medium">
                                                            Attempts
                                                        </dt>
                                                        <dd className="text-muted-foreground">
                                                            {
                                                                deadLetter.attempts
                                                            }
                                                        </dd>
                                                    </div>
                                                    <div>
                                                        <dt className="font-medium">
                                                            Error type
                                                        </dt>
                                                        <dd className="text-muted-foreground">
                                                            {
                                                                deadLetter.errorType
                                                            }
                                                        </dd>
                                                    </div>
                                                </dl>

                                                {canReplay ? (
                                                    <form
                                                        className="mt-4 space-y-3"
                                                        onSubmit={(event) =>
                                                            replayDeadLetter(
                                                                event,
                                                                deadLetter,
                                                            )
                                                        }
                                                    >
                                                        <label
                                                            htmlFor={`reason-${key}`}
                                                            className="text-sm font-medium"
                                                        >
                                                            Replay reason
                                                        </label>
                                                        <textarea
                                                            id={`reason-${key}`}
                                                            value={reason}
                                                            onChange={(event) =>
                                                                setReasons(
                                                                    (
                                                                        current,
                                                                    ) => ({
                                                                        ...current,
                                                                        [key]: event
                                                                            .target
                                                                            .value,
                                                                    }),
                                                                )
                                                            }
                                                            rows={3}
                                                            maxLength={1000}
                                                            className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                                            placeholder="Explain why replay is safe and what was remediated."
                                                        />

                                                        <Button
                                                            type="submit"
                                                            disabled={
                                                                reason.trim()
                                                                    .length <
                                                                    10 ||
                                                                isReplaying
                                                            }
                                                        >
                                                            <RotateCcw aria-hidden="true" />
                                                            {isReplaying
                                                                ? 'Replaying…'
                                                                : 'Replay safely'}
                                                        </Button>
                                                    </form>
                                                ) : (
                                                    <Alert className="mt-4">
                                                        <ShieldAlert aria-hidden="true" />
                                                        <AlertTitle>
                                                            Approval required
                                                        </AlertTitle>
                                                        <AlertDescription>
                                                            An organization
                                                            owner or
                                                            administrator must
                                                            authorize replay.
                                                        </AlertDescription>
                                                    </Alert>
                                                )}
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </section>

                <p className="text-xs text-muted-foreground">
                    Fingerprint {recovery.metadata.fingerprint.slice(0, 12)}…
                </p>
            </main>
        </>
    );
}

/**
 * Render one recovery count.
 */
function SummaryCard({ label, value }: { label: string; value: number }) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium">{label}</CardTitle>
            </CardHeader>
            <CardContent>
                <p className="text-3xl font-semibold tabular-nums">{value}</p>
            </CardContent>
        </Card>
    );
}

/**
 * Render one calm empty state.
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
 * Resolve display-only recovery severity.
 */
function badgeVariant(
    kind: RecoveryExecution['kind'],
): 'destructive' | 'secondary' {
    return kind === 'retry' ? 'secondary' : 'destructive';
}

/**
 * Convert enum-style values to readable text.
 */
function humanize(value: string): string {
    return value
        .replaceAll('_', ' ')
        .replaceAll('-', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

/**
 * Format one ISO timestamp.
 */
function formatDate(value: string): string {
    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? 'Unknown time'
        : date.toLocaleString();
}
