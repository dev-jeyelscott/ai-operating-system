import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router: { reload: vi.fn() },
    usePoll: vi.fn(),
}));

import { OperationsDashboardContent } from './index';
import type { OperationsData } from './index';

/**
 * Build a stable operations payload for component tests.
 */
function operations(): OperationsData {
    return {
        metadata: {
            schemaVersion: 1,
            asOf: '2026-07-31T19:00:00+08:00',
            fingerprint: 'a'.repeat(64),
        },
        project: {
            id: 10,
            name: 'AI Operating System',
            slug: 'ai-operating-system',
            status: 'active',
            archived: false,
        },
        workflow: {
            id: '01KWORKFLOW0000000000000000',
            state: 'running',
            transitionSequence: 12,
            completedAt: null,
        },
        roadmap: {
            id: 20,
            revision: 1,
            status: 'approved',
            readiness: 'ready',
            approvedAt: '2026-07-31T18:00:00+08:00',
        },
        summary: {
            activeAgents: 1,
            ticketsTotal: 1,
            ticketsByStatus: { ready: 1 },
            blockers: 1,
            pendingApprovals: 1,
            retriesScheduled: 1,
            recentDecisions: 1,
        },
        layers: [
            {
                key: 'planning',
                label: 'Planning',
                state: 'completed',
                activeAgents: 0,
                agents: [],
            },
            {
                key: 'development',
                label: 'Development',
                state: 'working',
                activeAgents: 1,
                agents: ['01KEXECUTION000000000000000'],
            },
            {
                key: 'quality_assurance',
                label: 'Quality Assurance',
                state: 'idle',
                activeAgents: 0,
                agents: [],
            },
            {
                key: 'operations',
                label: 'Operations',
                state: 'idle',
                activeAgents: 0,
                agents: [],
            },
        ],
        agents: [
            {
                id: '01KEXECUTION000000000000000',
                role: 'Backend Engineer',
                layer: 'development',
                capability: 'development_execution',
                state: 'running',
                active: true,
                provider: 'simulation',
                requestedReasoning: 'medium',
                effectiveReasoning: 'simulated',
                ticketId: 'AIOS-120',
                attemptCount: 1,
                retryLimit: 2,
                nextAttemptAt: null,
                startedAt: '2026-07-31T18:30:00+08:00',
                finishedAt: null,
                contextUrl: '/development/executions/1',
            },
        ],
        tickets: [
            {
                id: 'AIOS-120',
                databaseId: 120,
                title: 'Build accessible operational dashboard',
                logicalAgent: 'Frontend Engineer',
                status: 'ready',
                desiredState: 'in_progress',
                reportedState: 'ready',
                observedState: 'ready',
                actualState: 'unverified',
                priority: 'high',
                risk: 'medium',
                position: 120,
                blocked: false,
                activeLease: true,
                leaseExpiresAt: '2026-07-31T20:00:00+08:00',
                contextUrl: '/roadmaps/1/tasks/120',
            },
        ],
        blockers: [
            {
                type: 'ticket',
                id: 'AIOS-119',
                title: 'Projection rebuild blocked',
                message: 'The ticket requires remediation.',
                contextUrl: '/roadmaps/1/tasks/119#blocker',
            },
        ],
        approvals: [
            {
                id: '01KAPPROVAL000000000000000',
                type: 'recovery',
                status: 'pending',
                executionId: '01KEXECUTION000000000000000',
                requestedAt: '2026-07-31T18:45:00+08:00',
                expiresAt: '2026-07-31T20:45:00+08:00',
                contextUrl: '/audit#approval',
            },
        ],
        retries: [
            {
                executionId: '01KEXECUTION000000000000000',
                capability: 'development_execution',
                logicalRole: 'Backend Engineer',
                attemptCount: 1,
                retryLimit: 2,
                nextAttemptAt: '2026-07-31T19:05:00+08:00',
                contextUrl: '/audit#execution',
            },
        ],
        decisions: [
            {
                id: '01KDECISION000000000000000',
                assessmentId: '01KASSESSMENT0000000000000',
                ticketId: 'AIOS-114',
                action: 'defer',
                reason: null,
                simulated: true,
                actualState: 'unverified',
                decidedAt: '2026-07-31T18:50:00+08:00',
                contextUrl: '/quality-assurance#decision-center',
            },
        ],
    };
}

describe('OperationsDashboardContent', () => {
    it('keeps simulation and unverified state visible', () => {
        render(
            <OperationsDashboardContent
                organizationName="AIOS Engineering"
                operations={operations()}
                approvalInboxUrl="/approvals"
            />,
        );

        expect(
            screen.getByText('Simulation remains unverified'),
        ).toBeInTheDocument();
        expect(screen.getAllByText('Simulated').length).toBeGreaterThan(0);
        expect(screen.getByText('Unverified')).toBeInTheDocument();
    });

    it('uses semantic tables and exposes every context action as a link', () => {
        render(
            <OperationsDashboardContent
                organizationName="AIOS Engineering"
                operations={operations()}
                approvalInboxUrl="/approvals"
            />,
        );

        const agents = screen.getByRole('table', {
            name: /logical agents, workflow layers/i,
        });
        const tickets = screen.getByRole('table', {
            name: /authoritative ticket status/i,
        });

        expect(
            within(agents).getByRole('columnheader', { name: 'Role' }),
        ).toBeInTheDocument();
        expect(
            within(agents).getByRole('rowheader', {
                name: 'Backend Engineer',
            }),
        ).toBeInTheDocument();
        expect(
            within(tickets).getByRole('rowheader', { name: /AIOS-120/i }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Review blocker' }),
        ).toHaveAttribute('href', '/roadmaps/1/tasks/119#blocker');
        expect(
            screen.getByRole('link', { name: 'Open inbox item' }),
        ).toHaveAttribute('href', expect.stringContaining('/approvals'));
    });
});
