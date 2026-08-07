import { usePoll } from '@inertiajs/react';
import { render } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router: {
        reload: vi.fn(),
    },
    usePoll: vi.fn(),
}));

import ProjectOperationsDashboard from './index';
import type { OperationsData } from './index';

/**
 * Build the smallest valid operations contract for polling tests.
 */
function emptyOperations(): OperationsData {
    return {
        metadata: {
            schemaVersion: 1,
            asOf: '2026-07-31T23:00:00+08:00',
            fingerprint: 'a'.repeat(64),
        },
        project: {
            id: 10,
            name: 'AI Operating System',
            slug: 'ai-operating-system',
            status: 'active',
            archived: false,
        },
        workflow: null,
        roadmap: null,
        summary: {
            activeAgents: 0,
            ticketsTotal: 0,
            ticketsByStatus: {},
            blockers: 0,
            pendingApprovals: 0,
            retriesScheduled: 0,
            recentDecisions: 0,
        },
        layers: [
            {
                key: 'planning',
                label: 'Planning',
                state: 'idle',
                activeAgents: 0,
                agents: [],
            },
            {
                key: 'development',
                label: 'Development',
                state: 'idle',
                activeAgents: 0,
                agents: [],
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
        agents: [],
        tickets: [],
        blockers: [],
        approvals: [],
        retries: [],
        decisions: [],
    };
}

describe('ProjectOperationsDashboard polling', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('polls only operations and tracks the refresh lifecycle', () => {
        render(
            <ProjectOperationsDashboard
                organization={{
                    id: 1,
                    name: 'AIOS Engineering',
                    slug: 'aios-engineering',
                }}
                project={{
                    id: 10,
                    name: 'AI Operating System',
                    slug: 'ai-operating-system',
                    status: 'active',
                    terminal: false,
                }}
                projectUrl="/organizations/1/projects/10"
                approvalInboxUrl="/organizations/1/projects/10/approvals"
                recoveryCenterUrl="/organizations/1/projects/10/operations/recovery"
                usageUrl="/organizations/1/projects/10/operations/usage"
                officeProjectionUrl="/organizations/1/projects/10/operations/office-projection"
                operations={emptyOperations()}
            />,
        );

        expect(vi.mocked(usePoll)).toHaveBeenCalledTimes(1);

        expect(vi.mocked(usePoll)).toHaveBeenCalledWith(
            10_000,
            expect.objectContaining({
                only: ['operations'],
                onStart: expect.any(Function),
                onFinish: expect.any(Function),
            }),
        );
    });
});
