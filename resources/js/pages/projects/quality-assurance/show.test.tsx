import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

let renderDeferredFallback = false;

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
}));

import QualityAssuranceReportPage, { ReportContent } from './show';
import type {
    QualityAssuranceAssessment,
    QualityAssuranceReportData,
    QualityAssuranceReportPageProps,
} from './show';

const evidenceId = '01KYY3NGD80M9W1D3FQ3T32B5Q';

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
                verified: false,
                provider: 'simulation',
                sourceReference: 'simulation://qa/evidence',
                claims: ['Synthetic validation output only.'],
            },
        ],
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
        expect(screen.getByText('Merge Ready With Risks')).toBeInTheDocument();
        expect(screen.getByText('88%')).toBeInTheDocument();
        expect(screen.getByText('develop')).toBeInTheDocument();
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
