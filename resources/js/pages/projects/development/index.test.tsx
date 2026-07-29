import { render, screen, within } from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

let renderDeferredFallback = false;
const poll = vi.fn();

vi.mock('@inertiajs/react', async () => {
    const { forwardRef } = await import('react');

    return {
        Deferred: ({
            children,
            fallback,
        }: {
            children: ReactNode;
            fallback: ReactNode;
        }) => (renderDeferredFallback ? fallback : children),
        Head: () => null,
        Link: forwardRef<
            HTMLAnchorElement,
            AnchorHTMLAttributes<HTMLAnchorElement> & { href: string }
        >(function MockLink({ children, href, ...attributes }, reference) {
            return (
                <a ref={reference} href={href} {...attributes}>
                    {children}
                </a>
            );
        }),
        usePoll: (...arguments_: unknown[]) => poll(...arguments_),
    };
});

import DevelopmentQueuePage, { QueueContent } from './index';
import type {
    DevelopmentQueue,
    DevelopmentQueuePageProps,
    DevelopmentQueueTicket,
} from './index';

function ticket(
    overrides: Partial<DevelopmentQueueTicket> = {},
): DevelopmentQueueTicket {
    return {
        id: 'AIOS-100',
        title: 'Build the project queue',
        objectiveSummary: 'Explain deterministic ticket eligibility.',
        priority: 'high',
        risk: 'medium',
        roadmapPosition: 1,
        criticalPath: { active: true, rank: 1 },
        status: 'ready',
        desiredState: 'ready',
        reportedState: null,
        observedState: null,
        actualState: 'unverified',
        dependencies: [],
        approvalState: 'not_required',
        activeLease: false,
        leaseExpiresAt: null,
        executionState: null,
        attemptCount: 0,
        retryLimit: 3,
        nextAttemptAt: null,
        providerAvailable: true,
        budgetAvailable: true,
        ineligibilityReasonCodes: [],
        inspectorExecutionId: null,
        ...overrides,
    };
}

function queue(overrides: Partial<DevelopmentQueue> = {}): DevelopmentQueue {
    return {
        metadata: {
            asOf: new Date().toISOString(),
            approvedRoadmapId: 100,
            approvedRoadmapRevision: 7,
            queueFingerprint: 'a'.repeat(64),
            noWorkableTicket: false,
        },
        workable: [ticket()],
        ineligible: [],
        retryScheduled: [],
        ...overrides,
    };
}

function pageProps(
    overrides: Partial<DevelopmentQueuePageProps> = {},
): DevelopmentQueuePageProps {
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
        queue: queue(),
        leases: [],
        ...overrides,
    };
}

describe('DevelopmentQueuePage', () => {
    it('renders workable tickets in the server-provided order', () => {
        render(
            <QueueContent
                queue={queue({
                    workable: [
                        ticket({ id: 'AIOS-100' }),
                        ticket({ id: 'AIOS-101' }),
                    ],
                })}
                leases={[]}
            />,
        );

        const section = screen.getByRole('region', { name: 'Workable queue' });
        expect(
            within(section)
                .getAllByText(/AIOS-10[01]/)
                .map((node) => node.textContent),
        ).toEqual(['AIOS-100', 'AIOS-101']);
    });

    it('renders human-readable blocked reasons', () => {
        render(
            <QueueContent
                queue={queue({
                    workable: [],
                    ineligible: [
                        ticket({
                            status: 'blocked',
                            ineligibilityReasonCodes: [
                                'dependency_incomplete',
                                'approval_missing',
                            ],
                        }),
                    ],
                    metadata: { ...queue().metadata, noWorkableTicket: true },
                })}
                leases={[]}
            />,
        );

        expect(
            screen.getByText('A dependency is incomplete'),
        ).toBeInTheDocument();
        expect(screen.getByText('Approval is required')).toBeInTheDocument();
        expect(
            screen.getByText('No workable ticket right now'),
        ).toBeInTheDocument();
    });

    it('renders active lease and retry state without treating either as an error', () => {
        render(
            <QueueContent
                queue={queue({
                    retryScheduled: [
                        ticket({
                            executionState: 'retry_scheduled',
                            nextAttemptAt: new Date(
                                Date.now() + 60_000,
                            ).toISOString(),
                        }),
                    ],
                })}
                leases={[
                    {
                        ticketId: 'AIOS-099',
                        expiresAt: new Date(Date.now() + 60_000).toISOString(),
                        expired: false,
                    },
                ]}
            />,
        );

        expect(
            screen.getByRole('region', { name: 'Active leases' }),
        ).toHaveTextContent('AIOS-099');
        expect(
            screen.getByRole('region', { name: 'Retry-scheduled work' }),
        ).toHaveTextContent('Retry Scheduled');
    });

    it('shows an accessible loading state and deferred rescue state', () => {
        renderDeferredFallback = true;
        const { rerender } = render(<DevelopmentQueuePage {...pageProps()} />);
        expect(
            screen.getByLabelText('Loading development queue'),
        ).toHaveAttribute('aria-busy', 'true');

        renderDeferredFallback = false;
        rerender(<DevelopmentQueuePage {...pageProps({ queue: undefined })} />);
        expect(screen.getByText('Queue data did not load')).toBeInTheDocument();
    });

    it('polls only queue props and disables polling for terminal projects', () => {
        render(
            <DevelopmentQueuePage
                {...pageProps({
                    project: { ...pageProps().project, terminal: true },
                })}
            />,
        );

        expect(poll).toHaveBeenCalledWith(
            5_000,
            { only: ['queue', 'leases'] },
            { autoStart: false },
        );
    });

    it('builds project and inspector links with typed route parameters', () => {
        render(
            <DevelopmentQueuePage
                {...pageProps({
                    queue: queue({
                        workable: [
                            ticket({
                                inspectorExecutionId:
                                    '01KYPAB5S2ETWGGMB4TFTVWX1E',
                            }),
                        ],
                    }),
                })}
            />,
        );

        expect(
            screen.getByRole('link', { name: 'Back to project' }),
        ).toHaveAttribute(
            'href',
            '/organizations/aios-engineering/projects/ai-operating-system',
        );
        expect(
            screen.getByRole('link', { name: 'Inspect execution' }),
        ).toHaveAttribute(
            'href',
            '/organizations/aios-engineering/projects/ai-operating-system/development/executions/01KYPAB5S2ETWGGMB4TFTVWX1E',
        );
    });
});
