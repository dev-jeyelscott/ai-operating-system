import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ComponentProps, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

const inertiaMocks = vi.hoisted(() => ({
    post: vi.fn(),
    usePoll: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router: {
        post: inertiaMocks.post,
    },
    usePoll: inertiaMocks.usePoll,
}));

import ProjectRecoveryCenter from './recovery';

type RecoveryData = ComponentProps<typeof ProjectRecoveryCenter>['recovery'];

/**
 * Build a stable recovery payload for component tests.
 */
function recoveryData(): RecoveryData {
    return {
        metadata: {
            asOf: '2026-08-01T10:00:00+08:00',
            fingerprint: 'a'.repeat(64),
        },
        summary: {
            blocked: 1,
            retryScheduled: 1,
            failed: 1,
            deadLetters: 1,
        },
        executions: [
            {
                id: '01KRECOVERYEXECUTION000000000',
                kind: 'blocked',
                status: 'blocked',
                capability: 'development.simulation',
                logicalRole: 'backend_engineer',
                provider: 'simulation',
                attemptCount: 2,
                retryLimit: 3,
                nextAttemptAt: null,
                errorCode: 'provider_unavailable',
                errorMessage: 'The simulation provider is unavailable.',
                retryable: true,
                finishedAt: null,
                simulated: true,
                recommendedAction:
                    'Restore the dependency before requesting replay.',
                contextUrl: '/operations/executions/1',
            },
        ],
        deadLetters: [
            {
                source: 'outbox',
                id: '01KDEADLETTER000000000000000',
                eventId: '01KDEADLETTER000000000000000',
                eventName: 'workflow.retry_scheduled',
                attempts: 3,
                failedAt: '2026-08-01T09:30:00+08:00',
                errorType: 'RuntimeException',
            },
        ],
    };
}

/**
 * Render the recovery page with overridable authorization.
 */
function renderRecoveryCenter(canReplay = true) {
    return render(
        <ProjectRecoveryCenter
            project={{
                id: 10,
                name: 'AI Operating System',
                slug: 'ai-operating-system',
            }}
            operationsUrl="/organizations/1/projects/10/operations"
            usageUrl="/organizations/1/projects/10/operations/usage"
            replayUrl="/organizations/1/projects/10/operations/recovery/replay"
            canReplay={canReplay}
            recovery={recoveryData()}
        />,
    );
}

describe('ProjectRecoveryCenter', () => {
    it('renders authoritative recovery state and simulation labels', () => {
        renderRecoveryCenter();

        expect(
            screen.getByRole('heading', {
                name: 'Blocker and recovery center',
            }),
        ).toBeInTheDocument();

        const recoverySummary = screen.getByRole('region', {
            name: 'Recovery summary',
        });

        expect(
            within(recoverySummary).getByText('Blocked'),
        ).toBeInTheDocument();

        expect(
            within(recoverySummary).getByText('Scheduled retries'),
        ).toBeInTheDocument();

        expect(
            within(recoverySummary).getByText('Terminal failures'),
        ).toBeInTheDocument();

        expect(
            within(recoverySummary).getByText('Dead letters'),
        ).toBeInTheDocument();

        expect(screen.getByText('Simulated')).toBeInTheDocument();
        expect(screen.getByText('Provider Unavailable')).toBeInTheDocument();

        expect(
            screen.getByRole('link', {
                name: 'Inspect execution',
            }),
        ).toHaveAttribute('href', '/operations/executions/1');
    });

    it('requires an auditable reason before submitting replay', async () => {
        const user = userEvent.setup();

        renderRecoveryCenter();

        const reason = screen.getByLabelText('Replay reason');
        const submit = screen.getByRole('button', {
            name: 'Replay safely',
        });

        expect(submit).toBeDisabled();

        await user.type(reason, 'The dependency has recovered safely.');

        expect(submit).toBeEnabled();

        await user.click(submit);

        expect(inertiaMocks.post).toHaveBeenCalledTimes(1);

        expect(inertiaMocks.post).toHaveBeenCalledWith(
            '/organizations/1/projects/10/operations/recovery/replay',
            {
                source: 'outbox',
                identifier: '01KDEADLETTER000000000000000',
                reason: 'The dependency has recovered safely.',
            },
            expect.objectContaining({
                preserveScroll: true,
                onSuccess: expect.any(Function),
                onFinish: expect.any(Function),
            }),
        );
    });

    it('does not expose replay controls to unauthorized members', () => {
        renderRecoveryCenter(false);

        expect(screen.getByText('Approval required')).toBeInTheDocument();

        expect(
            screen.getByText(/owner or administrator must authorize replay/i),
        ).toBeInTheDocument();

        expect(
            screen.queryByLabelText('Replay reason'),
        ).not.toBeInTheDocument();

        expect(
            screen.queryByRole('button', {
                name: 'Replay safely',
            }),
        ).not.toBeInTheDocument();
    });
});
