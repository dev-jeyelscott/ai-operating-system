import { Deferred, Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    CircleAlert,
    GitBranch,
    ShieldAlert,
    XCircle,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

export type QaFinding = {
    code: string;
    dimension: string;
    severity: string;
    blocking: boolean;
    summary: string;
    impact: string;
    mitigation: string;
    evidenceIds: string[];
};

export type MergeRisk = {
    code: string;
    level: string;
    summary: string;
    impact: string;
    mitigation: string;
    evidenceIds: string[];
};

export type EvidenceReference = {
    id: string;
    available: boolean;
    classification: string;
    verified: boolean;
    provider: string | null;
    sourceReference: string | null;
    claims: string[];
};

export type QualityAssuranceAssessment = {
    id: string;
    status: string;
    ticket: {
        id: string;
        title: string;
        objective: string;
        status: string;
        actualState: string;
    } | null;
    decision: string | null;
    confidence: number | null;
    targetBranch: string | null;
    ticketScopeSatisfied: boolean | null;
    acceptanceCriteriaVerified: boolean | null;
    reviewStatuses: Array<{ label: string; status: string | null }>;
    riskMatrix: Array<{ label: string; level: string | null }>;
    findings: QaFinding[];
    mergeRisks: MergeRisk[];
    recommendation: string | null;
    evidenceReferences: EvidenceReference[];
    provenance: {
        isSimulated: boolean;
        provider: string | null;
        scenario: string;
        seed: number;
        actualState: string;
        evidenceStillRequired: boolean;
        schemaVersion: number | null;
        fingerprint: string | null;
    };
    createdAt: string;
    updatedAt: string;
};

export type QualityAssuranceReportData = {
    asOf: string;
    assessment: QualityAssuranceAssessment | null;
};

export type QualityAssuranceReportPageProps = {
    organization: { id: number; name: string; slug: string };
    project: {
        id: number;
        name: string;
        slug: string;
        status: string;
        terminal: boolean;
    };
    projectUrl: string;
    report?: QualityAssuranceReportData;
};

type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline';

/**
 * Render the project QA report shell while its read model loads separately.
 */
export default function QualityAssuranceReportPage({
    organization,
    project,
    projectUrl,
    report,
}: QualityAssuranceReportPageProps) {
    return (
        <>
            <Head title={`${project.name} QA report`} />
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
                                QA report and risk matrix
                            </h1>
                            <Badge variant="outline">
                                {humanize(project.status)}
                            </Badge>
                        </div>
                        <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                            Latest independent Layer 3 findings, residual merge
                            risks, evidence references, and advisory provenance
                            for {project.name}.
                        </p>
                    </div>
                    <div className="rounded-lg border bg-card px-4 py-3 text-sm">
                        <p className="font-medium">{organization.name}</p>
                        <p className="text-muted-foreground">
                            Read-only assessment
                        </p>
                    </div>
                </header>

                <Deferred data="report" fallback={<ReportSkeleton />}>
                    {report ? (
                        <ReportContent report={report} />
                    ) : (
                        <DeferredRescue />
                    )}
                </Deferred>
            </main>
        </>
    );
}

/**
 * Render the latest assessment or the project empty state.
 */
export function ReportContent({
    report,
}: {
    report: QualityAssuranceReportData;
}) {
    if (!report.assessment) {
        return <NoAssessmentState />;
    }

    const assessment = report.assessment;

    return (
        <div className="space-y-6">
            {assessment.provenance.isSimulated && (
                <Alert>
                    <ShieldAlert aria-hidden="true" />
                    <AlertTitle>Simulated and unverified</AlertTitle>
                    <AlertDescription>
                        This assessment is advisory. Its actual state is{' '}
                        <strong>
                            {humanize(assessment.provenance.actualState)}
                        </strong>
                        , evidence is still required, and it cannot authorize a
                        real repository merge.
                    </AlertDescription>
                </Alert>
            )}

            <SummaryCards assessment={assessment} asOf={report.asOf} />
            <ScopeAndReview assessment={assessment} />
            <RiskMatrix assessment={assessment} />
            <FindingsTable findings={assessment.findings} />
            <MergeRisksTable risks={assessment.mergeRisks} />
            <Recommendation assessment={assessment} />
            <EvidenceList evidence={assessment.evidenceReferences} />
        </div>
    );
}

/**
 * Render the primary report identity and merge-advisory facts.
 */
function SummaryCards({
    assessment,
    asOf,
}: {
    assessment: QualityAssuranceAssessment;
    asOf: string;
}) {
    return (
        <section aria-labelledby="assessment-summary-heading">
            <Card>
                <CardHeader>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <CardTitle id="assessment-summary-heading">
                            Assessment summary
                        </CardTitle>
                        <Badge variant={decisionVariant(assessment.decision)}>
                            {humanize(assessment.decision ?? assessment.status)}
                        </Badge>
                    </div>
                </CardHeader>
                <CardContent className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Detail
                        label="Confidence"
                        value={formatConfidence(assessment.confidence)}
                    />
                    <Detail
                        label="Target branch"
                        value={assessment.targetBranch ?? 'Not available'}
                        icon={
                            <GitBranch className="size-4" aria-hidden="true" />
                        }
                    />
                    <Detail
                        label="Ticket"
                        value={
                            assessment.ticket
                                ? `${assessment.ticket.id}: ${assessment.ticket.title}`
                                : 'Ticket unavailable'
                        }
                    />
                    <Detail label="Report as of" value={formatDate(asOf)} />
                </CardContent>
            </Card>
        </section>
    );
}

/**
 * Render ticket traceability and the four required review statuses.
 */
function ScopeAndReview({
    assessment,
}: {
    assessment: QualityAssuranceAssessment;
}) {
    return (
        <section aria-labelledby="scope-review-heading">
            <div className="mb-3">
                <h2 id="scope-review-heading" className="text-lg font-semibold">
                    Scope and review status
                </h2>
                <p className="text-sm text-muted-foreground">
                    Canonical ticket checks and required Layer 3 review
                    dimensions.
                </p>
            </div>
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <BooleanStatusCard
                    label="Ticket scope satisfied"
                    value={assessment.ticketScopeSatisfied}
                />
                <BooleanStatusCard
                    label="Acceptance criteria verified"
                    value={assessment.acceptanceCriteriaVerified}
                />
                {assessment.reviewStatuses.map((item) => (
                    <Card key={item.label}>
                        <CardContent className="flex items-center justify-between gap-3 py-5">
                            <span className="font-medium">{item.label}</span>
                            <Badge variant={reviewStatusVariant(item.status)}>
                                {humanize(item.status ?? 'unverified')}
                            </Badge>
                        </CardContent>
                    </Card>
                ))}
            </div>
        </section>
    );
}

/**
 * Render one nullable boolean result without relying on color alone.
 */
function BooleanStatusCard({
    label,
    value,
}: {
    label: string;
    value: boolean | null;
}) {
    const Icon = value === null ? CircleAlert : value ? CheckCircle2 : XCircle;
    const text =
        value === null ? 'Unverified' : value ? 'Satisfied' : 'Not satisfied';

    return (
        <Card>
            <CardContent className="flex items-center gap-3 py-5">
                <Icon className="size-5" aria-hidden="true" />
                <div>
                    <p className="font-medium">{label}</p>
                    <p className="text-sm text-muted-foreground">{text}</p>
                </div>
            </CardContent>
        </Card>
    );
}

/**
 * Render the database, performance, regression, and rollback risk matrix.
 */
function RiskMatrix({
    assessment,
}: {
    assessment: QualityAssuranceAssessment;
}) {
    return (
        <section aria-labelledby="risk-matrix-heading">
            <Card>
                <CardHeader>
                    <CardTitle id="risk-matrix-heading">Risk matrix</CardTitle>
                    <p className="text-sm text-muted-foreground">
                        Residual impact levels used by the advisory merge
                        disposition.
                    </p>
                </CardHeader>
                <CardContent className="overflow-x-auto">
                    <table className="w-full min-w-[36rem] border-collapse text-left text-sm">
                        <caption className="sr-only">
                            QA impact and merge-risk matrix
                        </caption>
                        <thead>
                            <tr className="border-b">
                                <th
                                    scope="col"
                                    className="px-3 py-3 font-medium"
                                >
                                    Area
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-3 font-medium"
                                >
                                    Level
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-3 font-medium"
                                >
                                    Interpretation
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {assessment.riskMatrix.map((item) => (
                                <tr
                                    key={item.label}
                                    className="border-b last:border-0"
                                >
                                    <th
                                        scope="row"
                                        className="px-3 py-4 font-medium"
                                    >
                                        {item.label}
                                    </th>
                                    <td className="px-3 py-4">
                                        <Badge
                                            variant={impactVariant(item.level)}
                                        >
                                            {humanize(
                                                item.level ?? 'unverified',
                                            )}
                                        </Badge>
                                    </td>
                                    <td className="px-3 py-4 text-muted-foreground">
                                        {impactDescription(item.level)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </section>
    );
}

/**
 * Render findings with severity, impact, mitigation, and evidence references.
 */
function FindingsTable({ findings }: { findings: QaFinding[] }) {
    return (
        <section aria-labelledby="findings-heading">
            <div className="mb-3 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 id="findings-heading" className="text-lg font-semibold">
                        Unresolved findings
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Blocking state and evidence lineage remain visible.
                    </p>
                </div>
                <Badge variant={findings.length ? 'destructive' : 'default'}>
                    {findings.length} finding{findings.length === 1 ? '' : 's'}
                </Badge>
            </div>

            {findings.length === 0 ? (
                <Card>
                    <CardContent className="py-6 text-sm text-muted-foreground">
                        No unresolved finding was reported.
                    </CardContent>
                </Card>
            ) : (
                <div className="grid gap-4 xl:grid-cols-2">
                    {findings.map((finding) => (
                        <Card key={finding.code}>
                            <CardHeader>
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <CardTitle className="font-mono text-sm">
                                        {finding.code}
                                    </CardTitle>
                                    <div className="flex flex-wrap gap-2">
                                        <Badge
                                            variant={impactVariant(
                                                finding.severity,
                                            )}
                                        >
                                            {humanize(finding.severity)}
                                        </Badge>
                                        {finding.blocking && (
                                            <Badge variant="destructive">
                                                Blocking
                                            </Badge>
                                        )}
                                    </div>
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {humanize(finding.dimension)}
                                </p>
                            </CardHeader>
                            <CardContent className="space-y-4 text-sm">
                                <ReportText
                                    label="Finding"
                                    value={finding.summary}
                                />
                                <ReportText
                                    label="Impact"
                                    value={finding.impact}
                                />
                                <ReportText
                                    label="Mitigation"
                                    value={finding.mitigation}
                                />
                                <div>
                                    <h3 className="font-medium">Evidence</h3>
                                    <div className="mt-2">
                                        <EvidenceIds
                                            ids={finding.evidenceIds}
                                        />
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            )}
        </section>
    );
}

/**
 * Render residual merge risks with their required explanatory fields.
 */
function MergeRisksTable({ risks }: { risks: MergeRisk[] }) {
    return (
        <section aria-labelledby="merge-risks-heading">
            <div className="mb-3 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2
                        id="merge-risks-heading"
                        className="text-lg font-semibold"
                    >
                        Residual merge risks
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Risks remaining after the Layer 3 assessment.
                    </p>
                </div>
                <Badge variant={risks.length ? 'secondary' : 'default'}>
                    {risks.length} risk{risks.length === 1 ? '' : 's'}
                </Badge>
            </div>

            {risks.length === 0 ? (
                <Card>
                    <CardContent className="py-6 text-sm text-muted-foreground">
                        No residual merge risk was reported.
                    </CardContent>
                </Card>
            ) : (
                <div className="grid gap-4 xl:grid-cols-2">
                    {risks.map((risk) => (
                        <Card key={risk.code}>
                            <CardHeader>
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <CardTitle className="font-mono text-sm">
                                        {risk.code}
                                    </CardTitle>
                                    <Badge variant={impactVariant(risk.level)}>
                                        {humanize(risk.level)}
                                    </Badge>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-4 text-sm">
                                <ReportText label="Risk" value={risk.summary} />
                                <ReportText
                                    label="Impact"
                                    value={risk.impact}
                                />
                                <ReportText
                                    label="Mitigation"
                                    value={risk.mitigation}
                                />
                                <div>
                                    <h3 className="font-medium">Evidence</h3>
                                    <div className="mt-2">
                                        <EvidenceIds ids={risk.evidenceIds} />
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            )}
        </section>
    );
}

/**
 * Render one labeled finding or risk explanation.
 */
function ReportText({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <h3 className="font-medium">{label}</h3>
            <p className="mt-1 leading-6 text-muted-foreground">{value}</p>
        </div>
    );
}

/**
 * Render opaque evidence identifiers as copyable code values.
 */
function EvidenceIds({ ids }: { ids: string[] }) {
    if (ids.length === 0) {
        return <span className="text-muted-foreground">None</span>;
    }

    return (
        <ul className="space-y-1">
            {ids.map((id) => (
                <li key={id}>
                    <code className="rounded bg-muted px-1.5 py-1 text-xs break-all">
                        {id}
                    </code>
                </li>
            ))}
        </ul>
    );
}

/**
 * Render the provider recommendation and immutable assessment provenance.
 */
function Recommendation({
    assessment,
}: {
    assessment: QualityAssuranceAssessment;
}) {
    return (
        <section aria-labelledby="recommendation-heading">
            <Card>
                <CardHeader>
                    <CardTitle id="recommendation-heading">
                        Recommendation
                    </CardTitle>
                </CardHeader>
                <CardContent className="grid gap-6 lg:grid-cols-[2fr_1fr]">
                    <p className="leading-7 text-muted-foreground">
                        {assessment.recommendation ??
                            'No recommendation is available yet.'}
                    </p>
                    <div className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-1">
                        <Detail
                            label="Provider"
                            value={humanize(
                                assessment.provenance.provider ?? 'unknown',
                            )}
                        />
                        <Detail
                            label="Scenario"
                            value={humanize(assessment.provenance.scenario)}
                        />
                        <Detail
                            label="Actual state"
                            value={humanize(assessment.provenance.actualState)}
                        />
                        <Detail
                            label="Schema version"
                            value={
                                assessment.provenance.schemaVersion === null
                                    ? 'Not available'
                                    : String(
                                          assessment.provenance.schemaVersion,
                                      )
                            }
                        />
                    </div>
                </CardContent>
            </Card>
        </section>
    );
}

/**
 * Render the project-scoped evidence records referenced by the report.
 */
function EvidenceList({ evidence }: { evidence: EvidenceReference[] }) {
    return (
        <section aria-labelledby="evidence-heading">
            <div className="mb-3">
                <h2 id="evidence-heading" className="text-lg font-semibold">
                    Evidence references
                </h2>
                <p className="text-sm text-muted-foreground">
                    Project-scoped records referenced by findings and merge
                    risks.
                </p>
            </div>
            {evidence.length === 0 ? (
                <Card>
                    <CardContent className="py-6 text-sm text-muted-foreground">
                        No evidence reference was attached to this assessment.
                    </CardContent>
                </Card>
            ) : (
                <div className="grid gap-4 lg:grid-cols-2">
                    {evidence.map((reference) => (
                        <Card key={reference.id}>
                            <CardHeader>
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <CardTitle className="font-mono text-sm break-all">
                                        {reference.id}
                                    </CardTitle>
                                    <div className="flex gap-2">
                                        <Badge
                                            variant={
                                                reference.available
                                                    ? 'outline'
                                                    : 'destructive'
                                            }
                                        >
                                            {reference.available
                                                ? humanize(
                                                      reference.classification,
                                                  )
                                                : 'Missing'}
                                        </Badge>
                                        <Badge
                                            variant={
                                                reference.verified
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                        >
                                            {reference.verified
                                                ? 'Verified'
                                                : 'Not verified'}
                                        </Badge>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <Detail
                                    label="Provider"
                                    value={humanize(
                                        reference.provider ?? 'unknown',
                                    )}
                                />
                                <Detail
                                    label="Source"
                                    value={
                                        reference.sourceReference ??
                                        'Not available inside this project boundary'
                                    }
                                />
                                {reference.claims.length > 0 && (
                                    <ul className="list-disc space-y-1 pl-5 text-muted-foreground">
                                        {reference.claims.map((claim) => (
                                            <li key={claim}>{claim}</li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </div>
            )}
        </section>
    );
}

/**
 * Render one small definition-list value.
 */
function Detail({
    label,
    value,
    icon,
}: {
    label: string;
    value: string;
    icon?: ReactNode;
}) {
    return (
        <div>
            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            <p className="mt-1 flex items-start gap-2 font-medium break-all">
                {icon}
                <span>{value}</span>
            </p>
        </div>
    );
}

/**
 * Render the neutral state before any Layer 3 assessment exists.
 */
function NoAssessmentState() {
    return (
        <Card>
            <CardContent className="py-12 text-center">
                <CircleAlert
                    className="mx-auto size-8 text-muted-foreground"
                    aria-hidden="true"
                />
                <h2 className="mt-4 text-lg font-semibold">
                    No QA assessment yet
                </h2>
                <p className="mx-auto mt-2 max-w-xl text-sm text-muted-foreground">
                    A report will appear after an eligible For QA ticket
                    completes an independent Layer 3 assessment.
                </p>
            </CardContent>
        </Card>
    );
}

/**
 * Render the deferred loading state.
 */
function ReportSkeleton() {
    return (
        <div
            aria-label="Loading QA report"
            aria-busy="true"
            className="space-y-6"
        >
            <Skeleton className="h-24 rounded-xl" />
            <Skeleton className="h-48 rounded-xl" />
            <Skeleton className="h-80 rounded-xl" />
        </div>
    );
}

/**
 * Render a recovery message when Inertia rescues the deferred query.
 */
function DeferredRescue() {
    return (
        <Alert variant="destructive">
            <AlertTriangle aria-hidden="true" />
            <AlertTitle>QA report data did not load</AlertTitle>
            <AlertDescription>
                Refresh the page. If the problem continues, inspect the project
                audit timeline and application logs.
            </AlertDescription>
        </Alert>
    );
}

/**
 * Map a QA decision to an existing badge variant.
 */
function decisionVariant(decision: string | null): BadgeVariant {
    if (decision === 'merge_ready') {
        return 'default';
    }

    if (decision === 'changes_requested' || decision === 'blocked') {
        return 'destructive';
    }

    if (
        decision === 'merge_ready_with_risks' ||
        decision === 'human_review_required'
    ) {
        return 'secondary';
    }

    return 'outline';
}

/**
 * Map a review status to an existing badge variant.
 */
function reviewStatusVariant(status: string | null): BadgeVariant {
    if (status === 'passed') {
        return 'default';
    }

    if (status === 'failed') {
        return 'destructive';
    }

    if (status === 'unverified') {
        return 'secondary';
    }

    return 'outline';
}

/**
 * Map a severity or impact level to an existing badge variant.
 */
function impactVariant(level: string | null): BadgeVariant {
    if (level === 'critical' || level === 'high') {
        return 'destructive';
    }

    if (level === 'medium') {
        return 'secondary';
    }

    return 'outline';
}

/**
 * Explain an impact level in plain language.
 */
function impactDescription(level: string | null): string {
    const descriptions: Record<string, string> = {
        critical: 'Release-blocking impact requiring remediation.',
        high: 'Material impact requiring explicit human review.',
        medium: 'Meaningful residual impact requiring mitigation.',
        low: 'Limited impact that remains visible and auditable.',
        none: 'No material impact identified for this dimension.',
    };

    return descriptions[level ?? ''] ?? 'The impact has not been verified.';
}

/**
 * Format nullable confidence as a percentage.
 */
function formatConfidence(confidence: number | null): string {
    return confidence === null
        ? 'Not available'
        : `${Math.round(confidence * 100)}%`;
}

/**
 * Format a nullable ISO timestamp for the current locale.
 */
function formatDate(value: string | null): string {
    if (!value) {
        return 'Not available';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? 'Not available'
        : date.toLocaleString();
}

/**
 * Convert stable snake-case values into readable labels.
 */
function humanize(value: string): string {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}
