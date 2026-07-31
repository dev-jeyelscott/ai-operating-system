import { Head, Link, usePoll } from '@inertiajs/react';
import {
    ArrowLeft,
    Calculator,
    CircleDollarSign,
    RefreshCw,
    RotateCcw,
    ShieldAlert,
} from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

type CurrencySummary = {
    currency: string;
    attempts: number;
    executions: number;
    estimatedSimulationCost: string;
    estimatedProviderCost: string;
    actualProviderCost: string;
};

type BreakdownRow = {
    currency: string;
    attempts: number;
    executions: number;
    estimatedSimulationCost: string;
    estimatedProviderCost: string;
    actualProviderCost: string;
    provider?: string;
    role?: string;
    reasoning?: string;
};

type UsageData = {
    metadata: {
        asOf: string;
        fingerprint: string;
    };
    summary: {
        attempts: number;
        executions: number;
        currencies: CurrencySummary[];
    };
    byProvider: BreakdownRow[];
    byRole: BreakdownRow[];
    byReasoning: BreakdownRow[];
    dataQuality: {
        simulationActualCostRecords: number;
        missingCurrencyRecords: number;
    };
};

type Props = {
    project: {
        id: number;
        name: string;
        slug: string;
    };
    operationsUrl: string;
    recoveryUrl: string;
    usage: UsageData;
};

/**
 * Render project usage with estimated and actual cost categories separated.
 */
export default function ProjectUsageView({
    project,
    operationsUrl,
    recoveryUrl,
    usage,
}: Props) {
    usePoll(30_000, {
        only: ['usage'],
        preserveState: true,
        preserveScroll: true,
    });

    const hasQualityIssues =
        usage.dataQuality.simulationActualCostRecords > 0 ||
        usage.dataQuality.missingCurrencyRecords > 0;

    return (
        <>
            <Head title={`${project.name} usage and costs`} />

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
                            Project usage and costs
                        </h1>

                        <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                            Estimated simulation cost, estimated real-provider
                            cost, and actual provider cost are intentionally
                            reported as separate values.
                        </p>
                    </div>

                    <Button asChild variant="outline">
                        <Link href={recoveryUrl}>
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
                    <RefreshCw className="size-4" aria-hidden="true" />
                    Usage updated {formatDate(usage.metadata.asOf)}.
                </div>

                <Alert>
                    <ShieldAlert aria-hidden="true" />
                    <AlertTitle>
                        Simulation remains an estimate
                    </AlertTitle>
                    <AlertDescription>
                        Simulation does not create actual provider charges.
                        Estimated simulation cost is never presented as billed
                        or verified cost.
                    </AlertDescription>
                </Alert>

                {hasQualityIssues && (
                    <Alert variant="destructive">
                        <ShieldAlert aria-hidden="true" />
                        <AlertTitle>Cost data needs review</AlertTitle>
                        <AlertDescription>
                            {
                                usage.dataQuality
                                    .simulationActualCostRecords
                            }{' '}
                            simulation attempt(s) contain actual cost and{' '}
                            {usage.dataQuality.missingCurrencyRecords} cost
                            record(s) have no currency.
                        </AlertDescription>
                    </Alert>
                )}

                <section
                    aria-label="Usage totals"
                    className="grid gap-4 sm:grid-cols-2"
                >
                    <MetricCard
                        label="Execution attempts"
                        value={usage.summary.attempts}
                    />
                    <MetricCard
                        label="Logical executions"
                        value={usage.summary.executions}
                    />
                </section>

                {usage.summary.currencies.length === 0 ? (
                    <Card>
                        <CardContent className="pt-6 text-sm text-muted-foreground">
                            No execution cost estimates have been recorded.
                        </CardContent>
                    </Card>
                ) : (
                    <section
                        aria-labelledby="cost-summary-heading"
                        className="space-y-4"
                    >
                        <h2
                            id="cost-summary-heading"
                            className="text-lg font-semibold"
                        >
                            Cost summary by currency
                        </h2>

                        {usage.summary.currencies.map((currency) => (
                            <Card key={currency.currency}>
                                <CardHeader>
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <CardTitle>
                                            {currency.currency}
                                        </CardTitle>
                                        <Badge variant="outline">
                                            {currency.attempts} attempts
                                        </Badge>
                                    </div>
                                </CardHeader>

                                <CardContent className="grid gap-4 md:grid-cols-3">
                                    <CostMetric
                                        label="Estimated simulation"
                                        value={currency.estimatedSimulationCost}
                                        currency={currency.currency}
                                        description="Planning estimate only; not billed."
                                    />
                                    <CostMetric
                                        label="Estimated provider"
                                        value={currency.estimatedProviderCost}
                                        currency={currency.currency}
                                        description="Pre-execution provider estimate."
                                    />
                                    <CostMetric
                                        label="Actual provider"
                                        value={currency.actualProviderCost}
                                        currency={currency.currency}
                                        description="Recorded non-simulation provider cost."
                                    />
                                </CardContent>
                            </Card>
                        ))}
                    </section>
                )}

                <BreakdownTable
                    heading="Usage by provider"
                    dimension="Provider"
                    rows={usage.byProvider}
                    resolveLabel={(row) => row.provider ?? 'Unknown'}
                />

                <BreakdownTable
                    heading="Usage by logical role"
                    dimension="Role"
                    rows={usage.byRole}
                    resolveLabel={(row) => row.role ?? 'Unassigned'}
                />

                <BreakdownTable
                    heading="Usage by reasoning level"
                    dimension="Reasoning"
                    rows={usage.byReasoning}
                    resolveLabel={(row) => row.reasoning ?? 'Unknown'}
                />

                <p className="text-xs text-muted-foreground">
                    Costs with different currencies are never cross-summed.
                    Fingerprint {usage.metadata.fingerprint.slice(0, 12)}…
                </p>
            </main>
        </>
    );
}

/**
 * Render one non-cost usage metric.
 */
function MetricCard({
    label,
    value,
}: {
    label: string;
    value: number;
}) {
    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between gap-3 pb-2">
                <CardTitle className="text-sm font-medium">
                    {label}
                </CardTitle>
                <Calculator
                    className="size-4 text-muted-foreground"
                    aria-hidden="true"
                />
            </CardHeader>
            <CardContent>
                <p className="text-3xl font-semibold tabular-nums">
                    {value}
                </p>
            </CardContent>
        </Card>
    );
}

/**
 * Render one clearly classified cost amount.
 */
function CostMetric({
    label,
    value,
    currency,
    description,
}: {
    label: string;
    value: string;
    currency: string;
    description: string;
}) {
    return (
        <div className="rounded-lg border p-4">
            <div className="flex items-center gap-2">
                <CircleDollarSign
                    className="size-4 text-muted-foreground"
                    aria-hidden="true"
                />
                <p className="text-sm font-medium">{label}</p>
            </div>
            <p className="mt-2 text-2xl font-semibold tabular-nums">
                {formatCost(value, currency)}
            </p>
            <p className="mt-1 text-xs text-muted-foreground">
                {description}
            </p>
        </div>
    );
}

/**
 * Render one semantic cost-breakdown table.
 */
function BreakdownTable({
    heading,
    dimension,
    rows,
    resolveLabel,
}: {
    heading: string;
    dimension: string;
    rows: BreakdownRow[];
    resolveLabel: (row: BreakdownRow) => string;
}) {
    return (
        <section aria-labelledby={slug(heading)}>
            <Card>
                <CardHeader>
                    <CardTitle id={slug(heading)}>{heading}</CardTitle>
                </CardHeader>
                <CardContent>
                    {rows.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No usage records.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[60rem] text-sm">
                                <caption className="sr-only">
                                    {heading} with separated estimated and
                                    actual cost fields
                                </caption>
                                <thead>
                                    <tr className="border-b text-left">
                                        <th className="px-3 py-3">
                                            {dimension}
                                        </th>
                                        <th className="px-3 py-3">
                                            Currency
                                        </th>
                                        <th className="px-3 py-3">
                                            Attempts
                                        </th>
                                        <th className="px-3 py-3">
                                            Executions
                                        </th>
                                        <th className="px-3 py-3">
                                            Estimated simulation
                                        </th>
                                        <th className="px-3 py-3">
                                            Estimated provider
                                        </th>
                                        <th className="px-3 py-3">
                                            Actual provider
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((row, index) => (
                                        <tr
                                            key={`${resolveLabel(row)}-${row.currency}-${index}`}
                                            className="border-b last:border-0"
                                        >
                                            <th className="px-3 py-3 text-left font-medium">
                                                {humanize(
                                                    resolveLabel(row),
                                                )}
                                            </th>
                                            <td className="px-3 py-3">
                                                {row.currency}
                                            </td>
                                            <td className="px-3 py-3 tabular-nums">
                                                {row.attempts}
                                            </td>
                                            <td className="px-3 py-3 tabular-nums">
                                                {row.executions}
                                            </td>
                                            <td className="px-3 py-3 tabular-nums">
                                                {formatCost(
                                                    row.estimatedSimulationCost,
                                                    row.currency,
                                                )}
                                            </td>
                                            <td className="px-3 py-3 tabular-nums">
                                                {formatCost(
                                                    row.estimatedProviderCost,
                                                    row.currency,
                                                )}
                                            </td>
                                            <td className="px-3 py-3 tabular-nums">
                                                {formatCost(
                                                    row.actualProviderCost,
                                                    row.currency,
                                                )}
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
 * Format a persisted cost without pretending an unspecified currency is known.
 */
function formatCost(value: string, currency: string): string {
    const numericValue = Number(value);

    if (currency === 'UNSPECIFIED') {
        return `${numericValue.toFixed(8)} · currency unspecified`;
    }

    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency,
            minimumFractionDigits: 2,
            maximumFractionDigits: 8,
        }).format(numericValue);
    } catch {
        return `${numericValue.toFixed(8)} ${currency}`;
    }
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
 * Create a stable local heading identifier.
 */
function slug(value: string): string {
    return value.toLowerCase().replaceAll(' ', '-');
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
