import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

let renderDeferredFallback = false;
let formProcessing = false;
let formErrors: Record<string, string> = {};
let formDataOverrides: Record<string, string> = {};

vi.mock('@inertiajs/react', () => ({
    Deferred: ({
        children,
        fallback,
    }: {
        children: ReactNode;
        fallback: ReactNode;
    }) => (renderDeferredFallback ? fallback : children),
    Head: () => null,
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    useForm: <T extends Record<string, string>>(initial: T) => {
        const [data, setDataState] = useState({
            ...initial,
            ...formDataOverrides,
        });

        return {
            data,
            setData: (keyOrData: keyof T | T, value?: string) => {
                if (typeof keyOrData === 'object') {
                    setDataState(keyOrData);

                    return;
                }

                setDataState((current) => ({
                    ...current,
                    [keyOrData]: value ?? '',
                }));
            },
            post: vi.fn(),
            processing: formProcessing,
            errors: formErrors as Partial<Record<keyof T, string>>,
            clearErrors: vi.fn(),
        };
    },
}));

import QualityAssuranceReportPage, { ReportContent } from './show';
import type {
    QualityAssuranceAssessment,
    QualityAssuranceReportData,
    QualityAssuranceReportPageProps,
} from './show';

const evidenceId = '01KYY3NGD80M9W1D3FQ3T32B5Q';

beforeEach(() => {
    renderDeferredFallback = false;
    formProcessing = false;
    formErrors = {};
    formDataOverrides = {};
});

/**
 * Build one completed high-risk assessment for component tests.
 */
function assessment(
    overrides: Partial<QualityAssuranceAssessment> = {},
): QualityAssuranceAssessment {
    return {
        id: '01KYY3N8EQ9T7V4ZXK8RCH2M6J',
        status: 'completed',
        ticket: {
            id: 'AIOS-111',
            title: 'Build QA report and risk matrix UI',
            objective: 'Present the latest independent QA assessment.',
            status: 'for_qa',
            actualState: 'unverified',
        },
        decision: 'merge_ready_with_risks',
        confidence: 0.88,
        targetBranch: 'develop',
        ticketScopeSatisfied: true,
        acceptanceCriteriaVerified: true,
        reviewStatuses: [
            { label: 'CI', status: 'passed' },
            { label: 'Tests', status: 'passed' },
            { label: 'Architecture', status: 'passed' },
            { label: 'Security', status: 'passed' },
        ],
        riskMatrix: [
            { label: 'Database impact', level: 'high' },
            { label: 'Performance impact', level: 'medium' },
            { label: 'Regression risk', level: 'high' },
            { label: 'Rollback complexity', level: 'high' },
        ],
        findings: [
            {
                code: 'QA-RISK-001',
                dimension: 'operational_impact',
                severity: 'high',
                blocking: false,
                summary:
                    'The simulated change has broad operational and rollback impact.',
                impact: 'A defect could affect critical workflows and require complex recovery.',
                mitigation:
                    'Require explicit human review of rollout and rollback evidence.',
                evidenceIds: [evidenceId],
            },
        ],
        mergeRisks: [
            {
                code: 'MERGE-HIGH-001',
                level: 'high',
                summary:
                    'Residual regression and rollback risk exceeds the approval threshold.',
                impact: 'Automatic approval would exceed the MVP autonomy policy.',
                mitigation:
                    'Escalate to an authorized human and require verified evidence.',
                evidenceIds: [evidenceId],
            },
        ],
        recommendation:
            'Escalate for explicit human review. The simulated result must not be approved automatically.',
        evidenceReferences: [
            {
                id: evidenceId,
                available: true,
                classification: 'simulated_output',
                state: 'simulated',
                verified: false,
                stale: false,
                provider: 'simulation',
                sourceReference: 'simulation://qa/evidence',
                claims: ['Synthetic validation output only.'],
                verifiedAt: null,
                expiresAt: null,
                reasonCode: 'evidence.simulated',
            },
        ],
        evidenceSummary: {
            total: 1,
            verified: 0,
            stale: 0,
            missing: 0,
            unverified: 1,
            allCurrentlyVerified: false,
        },
        decisionCenter: {
            submissionUrl:
                '/organizations/aios-engineering/projects/ai-operating-system/quality-assurance/assessments/01KYY3N8EQ9T7V4ZXK8RCH2M6J/decisions',
            expectedAssessmentFingerprint: 'a'.repeat(64),
            allowedActions: ['approve', 'request_changes', 'escalate', 'defer'],
            reasonRequiredActions: ['request_changes', 'escalate'],
            terminal: false,
            canSubmit: true,
            latestDecision: null,
        },
        provenance: {
            isSimulated: true,
            provider: 'simulation',
            scenario: 'merge_ready_high_risk',
            seed: 111,
            actualState: 'unverified',
            evidenceStillRequired: true,
            schemaVersion: 1,
            fingerprint: 'a'.repeat(64),
        },
        createdAt: '2026-07-30T09:00:00+08:00',
        updatedAt: '2026-07-30T09:01:00+08:00',
        ...overrides,
    };
}

/**
 * Build the deferred report prop used by the page.
 */
function report(
    overrides: Partial<QualityAssuranceReportData> = {},
): QualityAssuranceReportData {
    return {
        asOf: '2026-07-30T09:02:00+08:00',
        assessment: assessment(),
        ...overrides,
    };
}

/**
 * Build stable page props for shell and deferred-state tests.
 */
function pageProps(
    overrides: Partial<QualityAssuranceReportPageProps> = {},
): QualityAssuranceReportPageProps {
    return {
        organization: {
            id: 1,
            name: 'AIOS Engineering',
            slug: 'aios-engineering',
        },
        project: {
            id: 10,
            name: 'AI Operating System',
            slug: 'ai-operating-system',
            status: 'active',
            terminal: false,
        },
        projectUrl:
            '/organizations/aios-engineering/projects/ai-operating-system',
        report: report(),
        ...overrides,
    };
}

describe('QualityAssuranceReportPage', () => {
    it('keeps simulation and unverified state visible', () => {
        render(<ReportContent report={report()} />);

        expect(
            screen.getByText('Simulated and unverified'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/cannot authorize a real repository merge/i),
        ).toBeInTheDocument();
        expect(
            screen.getAllByText('Merge Ready With Risks').length,
        ).toBeGreaterThan(0);
        expect(screen.getByText('88%')).toBeInTheDocument();
        expect(screen.getAllByText('develop').length).toBeGreaterThan(0);
    });

    it('shows every required finding and merge-risk field', () => {
        render(<ReportContent report={report()} />);

        const findings = screen.getByRole('region', {
            name: 'Unresolved findings',
        });
        expect(within(findings).getByText('QA-RISK-001')).toBeInTheDocument();
        expect(within(findings).getByText('High')).toBeInTheDocument();
        expect(
            within(findings).getByText(/critical workflows/i),
        ).toBeInTheDocument();
        expect(
            within(findings).getByText(/explicit human review/i),
        ).toBeInTheDocument();
        expect(within(findings).getByText(evidenceId)).toBeInTheDocument();

        const risks = screen.getByRole('region', {
            name: 'Residual merge risks',
        });
        expect(within(risks).getByText('MERGE-HIGH-001')).toBeInTheDocument();
        expect(within(risks).getByText(/autonomy policy/i)).toBeInTheDocument();
        expect(within(risks).getByText(evidenceId)).toBeInTheDocument();
    });

    it('uses semantic row and column headers for the risk matrix', () => {
        render(<ReportContent report={report()} />);

        const matrix = screen.getByRole('table', {
            name: 'QA impact and merge-risk matrix',
        });
        expect(
            within(matrix).getByRole('columnheader', { name: 'Area' }),
        ).toBeInTheDocument();
        expect(
            within(matrix).getByRole('rowheader', {
                name: 'Regression risk',
            }),
        ).toBeInTheDocument();
        expect(within(matrix).getAllByText('High')).toHaveLength(3);
    });

    it('shows all four authorized actions and required reason fields', async () => {
        const user = userEvent.setup();
        render(<ReportContent report={report()} />);

        expect(
            screen.getByRole('button', {
                name: 'Approve simulated merge',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Request changes' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Escalate for review' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Defer decision' }),
        ).toBeInTheDocument();

        await user.click(
            screen.getByRole('button', { name: 'Request changes' }),
        );

        expect(screen.getByLabelText('Reason (required)')).toBeRequired();
        expect(
            screen.getByRole('button', {
                name: 'Confirm request changes',
            }),
        ).toBeDisabled();

        await user.click(
            screen.getByRole('button', { name: 'Escalate for review' }),
        );

        expect(screen.getByLabelText('Reason (required)')).toBeRequired();
    });

    it('shows validation, evidence, regression, and rollback summaries before actions', () => {
        render(<ReportContent report={report()} />);

        const decisionCenter = screen
            .getByRole('heading', {
                name: 'Simulated merge decision center',
            })
            .closest('section');

        expect(decisionCenter).not.toBeNull();
        expect(
            within(decisionCenter!).getByText('develop'),
        ).toBeInTheDocument();
        expect(
            within(decisionCenter!).getByText('4/4 checks passed'),
        ).toBeInTheDocument();
        expect(
            within(decisionCenter!).getByText('0 current, 0 stale, 0 missing'),
        ).toBeInTheDocument();
        expect(within(decisionCenter!).getAllByText('High')).toHaveLength(2);
    });

    it('disables decision controls while processing', () => {
        formProcessing = true;
        formDataOverrides = {
            action: 'defer',
            idempotency_key: `merge-decision:${assessment().id}:processing`,
            reason: '',
        };

        render(<ReportContent report={report()} />);

        expect(
            screen.getByRole('button', { name: 'Recording decision…' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', {
                name: 'Approve simulated merge',
            }),
        ).toBeDisabled();
        expect(screen.getByLabelText('Reason (optional)')).toBeDisabled();
    });

    it('renders server validation errors accessibly', () => {
        formDataOverrides = {
            action: 'request_changes',
            idempotency_key: `merge-decision:${assessment().id}:errors`,
            reason: 'Needs more evidence.',
        };
        formErrors = {
            reason: 'The reason must be more specific.',
            decision: 'The assessment changed before this decision.',
        };

        render(<ReportContent report={report()} />);

        const reasonError = screen.getByText(
            'The reason must be more specific.',
        );
        const decisionError = screen.getByText(
            'The assessment changed before this decision.',
        );

        expect(reasonError.closest('[role="alert"]')).not.toBeNull();
        expect(decisionError.closest('[role="alert"]')).not.toBeNull();
    });

    it('keeps the simulation warning persistent and non-dismissible', () => {
        render(<ReportContent report={report()} />);

        const warning = screen
            .getByText('Simulated and unverified')
            .closest<HTMLElement>('[role="alert"]');

        expect(warning).not.toBeNull();
        expect(within(warning!).queryByRole('button')).not.toBeInTheDocument();
    });

    it('renders a terminal decision as read-only', () => {
        render(
            <ReportContent
                report={report({
                    assessment: assessment({
                        decisionCenter: {
                            ...assessment().decisionCenter,
                            allowedActions: [],
                            terminal: true,
                            canSubmit: false,
                            latestDecision: {
                                action: 'approve',
                                reason: null,
                                decidedAt: '2026-07-30T09:03:00+08:00',
                                ticketStatusAfter: 'approved_for_merge',
                                terminal: true,
                                simulated: true,
                                actualState: 'unverified',
                            },
                        },
                    }),
                })}
            />,
        );

        expect(
            screen.getByText(/terminal human decision/i),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'Approve simulated merge',
            }),
        ).not.toBeInTheDocument();
        expect(screen.getByText('Approve simulated merge')).toBeInTheDocument();
    });

    it('renders stale, missing, simulated, and current evidence distinctly', () => {
        const staleId = '01KYY3NGD80M9W1D3FQ3T32B5R';
        const missingId = '01KYY3NGD80M9W1D3FQ3T32B5S';
        const verifiedId = '01KYY3NGD80M9W1D3FQ3T32B5T';
        const staleExpiry = '2026-07-30T08:00:00+08:00';

        render(
            <ReportContent
                report={report({
                    assessment: assessment({
                        evidenceReferences: [
                            {
                                id: evidenceId,
                                available: true,
                                classification: 'simulated_output',
                                state: 'simulated',
                                verified: false,
                                stale: false,
                                provider: 'simulation',
                                sourceReference: 'simulation://qa/evidence',
                                claims: [],
                                verifiedAt: null,
                                expiresAt: null,
                                reasonCode: 'evidence.simulated',
                            },
                            {
                                id: staleId,
                                available: true,
                                classification: 'verified_evidence',
                                state: 'stale',
                                verified: false,
                                stale: true,
                                provider: 'github',
                                sourceReference: 'github://checks/expired',
                                claims: [],
                                verifiedAt: '2026-07-29T08:00:00+08:00',
                                expiresAt: staleExpiry,
                                reasonCode: 'evidence.expired',
                            },
                            {
                                id: missingId,
                                available: false,
                                classification: 'missing',
                                state: 'missing',
                                verified: false,
                                stale: false,
                                provider: null,
                                sourceReference: null,
                                claims: [],
                                verifiedAt: null,
                                expiresAt: null,
                                reasonCode: 'evidence.missing',
                            },
                            {
                                id: verifiedId,
                                available: true,
                                classification: 'verified_evidence',
                                state: 'verified',
                                verified: true,
                                stale: false,
                                provider: 'github',
                                sourceReference: 'github://checks/current',
                                claims: [],
                                verifiedAt: '2026-07-30T07:00:00+08:00',
                                expiresAt: '2026-07-31T07:00:00+08:00',
                                reasonCode: 'evidence.currently_verified',
                            },
                        ],
                        evidenceSummary: {
                            total: 4,
                            verified: 1,
                            stale: 1,
                            missing: 1,
                            unverified: 1,
                            allCurrentlyVerified: false,
                        },
                    }),
                })}
            />,
        );

        expect(screen.getByText('Stale')).toBeInTheDocument();
        expect(screen.getAllByText('Missing').length).toBeGreaterThan(0);
        expect(
            screen.getByText('Simulated — not verified'),
        ).toBeInTheDocument();
        expect(screen.getByText('Verified')).toBeInTheDocument();
        expect(screen.getAllByText('Verified at')).toHaveLength(2);
        expect(screen.getByText('Expired at')).toBeInTheDocument();
        expect(screen.getByText('Expires at')).toBeInTheDocument();
        expect(
            screen.getByText('Simulated and unverified'),
        ).toBeInTheDocument();
    });

    it('renders the empty, loading, and rescued deferred states', () => {
        const { rerender } = render(
            <ReportContent report={report({ assessment: null })} />,
        );
        expect(screen.getByText('No QA assessment yet')).toBeInTheDocument();

        renderDeferredFallback = true;
        rerender(<QualityAssuranceReportPage {...pageProps()} />);
        expect(screen.getByLabelText('Loading QA report')).toHaveAttribute(
            'aria-busy',
            'true',
        );

        renderDeferredFallback = false;
        rerender(
            <QualityAssuranceReportPage
                {...pageProps({ report: undefined })}
            />,
        );
        expect(
            screen.getByText('QA report data did not load'),
        ).toBeInTheDocument();
    });
});
