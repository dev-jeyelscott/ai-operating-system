import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    usePoll: vi.fn(),
}));

import { ApprovalInboxContent } from './index';
import type { ApprovalInboxData } from './index';

/**
 * Build a stable inbox payload for accessibility tests.
 */
function inbox(): ApprovalInboxData {
    return {
        metadata: {
            asOf: '2026-07-31T19:00:00+08:00',
            fingerprint: 'b'.repeat(64),
        },
        summary: {
            total: 2,
            roadmap: 0,
            conflict: 1,
            recovery: 0,
            merge: 1,
            overdue: 0,
        },
        items: [
            {
                id: '41',
                category: 'conflict',
                source: 'notion_conflict',
                type: 'external_drift',
                title: 'AIOS-121: Build approval inbox',
                summary:
                    'External drift requires an explicit reconciliation decision.',
                status: 'open',
                requestedAt: '2026-07-31T18:00:00+08:00',
                expiresAt: null,
                urgency: 'normal',
                overdue: false,
                simulated: false,
                actualState: 'conflicted',
                contextUrl: '/roadmaps/1',
                contextLabel: 'Resolve conflict',
            },
            {
                id: '01KASSESSMENT0000000000000',
                category: 'merge',
                source: 'qa_assessment',
                type: 'merge_ready_with_risks',
                title: 'AIOS-114: QA end-to-end tests',
                summary: 'Review the independent QA assessment.',
                status: 'pending',
                requestedAt: '2026-07-31T18:30:00+08:00',
                expiresAt: null,
                urgency: 'normal',
                overdue: false,
                simulated: true,
                actualState: 'unverified',
                contextUrl: '/quality-assurance#decision-center',
                contextLabel: 'Review merge decision',
            },
        ],
    };
}

describe('ApprovalInboxContent', () => {
    it('shows every decision category and exact action link', () => {
        render(
            <ApprovalInboxContent
                organizationName="AIOS Engineering"
                inbox={inbox()}
                canApprove
                focusedApproval={null}
            />,
        );

        expect(screen.getByText('Conflict')).toBeInTheDocument();
        expect(screen.getByText('Merge')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Resolve conflict' }),
        ).toHaveAttribute('href', '/roadmaps/1');
        expect(
            screen.getByRole('link', { name: 'Review merge decision' }),
        ).toHaveAttribute('href', '/quality-assurance#decision-center');
    });

    it('preserves simulated and unverified warnings', () => {
        render(
            <ApprovalInboxContent
                organizationName="AIOS Engineering"
                inbox={inbox()}
                canApprove
                focusedApproval={null}
            />,
        );

        expect(
            screen.getByText('Simulated decisions are advisory'),
        ).toBeInTheDocument();
        expect(screen.getByText('Simulated')).toBeInTheDocument();
        expect(screen.getByText('Unverified')).toBeInTheDocument();
    });

    it('uses read-only action language for a viewer', () => {
        render(
            <ApprovalInboxContent
                organizationName="AIOS Engineering"
                inbox={inbox()}
                canApprove={false}
                focusedApproval={null}
            />,
        );

        expect(
            screen.getAllByRole('link', {
                name: 'View decision context',
            }),
        ).toHaveLength(2);
    });
});
