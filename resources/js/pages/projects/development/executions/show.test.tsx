import { render, screen } from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

let deferredFallback = false;
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
        }) => (deferredFallback ? fallback : children),
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
        useRemember: (initial: string) => [initial, vi.fn()],
    };
});

import DevelopmentExecutionInspectorPage, { InspectorContent } from './show';
import type {
    DevelopmentInspector,
    DevelopmentInspectorPageProps,
} from './show';

function inspector(
    overrides: Partial<DevelopmentInspector> = {},
): DevelopmentInspector {
    return {
        execution: {
            id: '01KDEVELOPMENTINSPECTOR',
            capability: 'development.simulation',
            status: 'completed',
            terminal: true,
            provider: 'simulation',
            requestedReasoning: 'high',
            attemptCount: 1,
            retryLimit: 3,
            nextAttemptAt: null,
            cancelRequestedAt: null,
            cancelledAt: null,
            cancellationReason: null,
            startedAt: '2026-07-29T08:00:00Z',
            finishedAt: '2026-07-29T08:01:00Z',
        },
        ticket: {
            id: 'AIOS-101',
            title: 'Layer 2 inspector',
            objective: 'Show safe provenance.',
            status: 'for_qa',
            actualState: 'unverified',
        },
        simulation: {
            simulated: true,
            verified: false,
            evidenceStillRequired: true,
        },
        contextSnapshot: {
            id: 1,
            configurationRevision: 7,
            identitySchemaVersion: 1,
            approvedDocumentSetFingerprint: 'a'.repeat(64),
            approvedDocumentCount: 2,
            createdAt: '2026-07-29T07:59:00Z',
        },
        attempts: [
            {
                id: 1,
                number: 1,
                status: 'completed',
                provider: 'simulation',
                modelIdentifier: null,
                requestedReasoning: 'high',
                effectiveReasoning: 'high',
                reasoningSource: 'immutable_configuration_snapshot',
                reasoningEscalationReason: null,
                simulationMode: 'simulated',
                simulationSeed: '101',
                actualState: 'unverified',
                confidence: '0.7500',
                error: null,
                deadlineAt: null,
                heartbeatAt: null,
                startedAt: '2026-07-29T08:00:00Z',
                finishedAt: '2026-07-29T08:01:00Z',
            },
        ],
        artifacts: [],
        plan: ['Inspect immutable scope.', 'Record simulated output.'],
        changedFiles: [
            {
                path: 'app/Simulated/Aios101.php',
                change_type: 'modified',
                summary: 'Synthetic only.',
            },
        ],
        diffSummary:
            'Synthetic manifest contains 1 changed file; no repository diff was produced.',
        validations: [
            {
                command: 'php artisan test',
                status: 'passed',
                summary: 'Simulated pass.',
            },
        ],
        repository: {
            branch: {
                name: 'feature/aios-101',
                reference: 'simulation://branch/aios-101',
            },
            commit: {
                name: 'a'.repeat(40),
                reference: 'simulation://commit/aios-101',
            },
            push: { name: 'push-101', reference: 'simulation://push/aios-101' },
            pullRequest: {
                name: 'pr-101',
                reference: 'simulation://pull-request/aios-101',
                target_branch: 'develop',
            },
        },
        assumptions: ['Simulation only.'],
        confidence: '0.7500',
        risks: ['No real QA.'],
        evidenceGaps: ['Real evidence remains required.'],
        missingArtifacts: [],
        lease: {
            id: 'lease-101',
            owner: 'worker-101',
            active: false,
            expired: false,
            expiredButExecutionLive: false,
            expiresAt: '2026-07-29T08:05:00Z',
            heartbeatAt: '2026-07-29T08:01:00Z',
            releasedAt: '2026-07-29T08:01:00Z',
            releaseReason: 'completion',
            recoveryState: 'released',
        },
        retry: {
            scheduled: false,
            attemptCount: 1,
            retryLimit: 3,
            nextAttemptAt: null,
        },
        error: null,
        auditTimeline: [
            {
                sequence: 1,
                type: 'implementation.started',
                attemptId: 1,
                leaseId: 'lease-101',
                occurredAt: '2026-07-29T08:00:00Z',
            },
        ],
        lifecycleEvents: [],
        ...overrides,
    };
}

function props(
    overrides: Partial<DevelopmentInspectorPageProps> = {},
): DevelopmentInspectorPageProps {
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
        },
        inspector: inspector(),
        ...overrides,
    };
}

describe('DevelopmentExecutionInspectorPage', () => {
    it('keeps simulation and verification warnings persistent', () => {
        render(<DevelopmentExecutionInspectorPage {...props()} />);

        expect(screen.getByText('Simulated execution')).toBeInTheDocument();
        expect(screen.getByText('Unverified result')).toBeInTheDocument();
        expect(
            screen.getByText('Real evidence still required'),
        ).toBeInTheDocument();
        expect(poll).toHaveBeenCalledWith(
            5_000,
            { only: ['inspector'] },
            { autoStart: false },
        );
        expect(
            screen.getByRole('link', { name: 'Back to development queue' }),
        ).toHaveAttribute(
            'href',
            '/organizations/aios-engineering/projects/ai-operating-system/development',
        );
    });

    it('keeps inspector polling active for a nonterminal execution', () => {
        render(
            <DevelopmentExecutionInspectorPage
                {...props({
                    inspector: inspector({
                        execution: {
                            ...inspector().execution,
                            status: 'running',
                            terminal: false,
                        },
                    }),
                })}
            />,
        );

        expect(poll).toHaveBeenCalledWith(
            5_000,
            { only: ['inspector'] },
            { autoStart: true },
        );
    });

    it('renders plan, attempts, timeline, validation, and synthetic artifacts', () => {
        render(<InspectorContent inspector={inspector()} />);

        expect(
            screen.getByRole('region', { name: 'Implementation plan' }),
        ).toHaveTextContent('Inspect immutable scope.');
        expect(
            screen.getByRole('region', { name: 'Attempts' }),
        ).toHaveTextContent('Attempt 1');
        expect(
            screen.getByRole('region', {
                name: 'Stage and lifecycle timeline',
            }),
        ).toHaveTextContent('Implementation Started');
        expect(
            screen.getByRole('region', { name: 'Validation' }),
        ).toHaveTextContent('php artisan test');
        expect(
            screen.getByRole('region', {
                name: 'Synthetic repository artifacts',
            }),
        ).toHaveTextContent('Target develop');
    });

    it('supports retry, failure, cancellation, and missing artifact states', () => {
        const retry = inspector({
            execution: {
                ...inspector().execution,
                status: 'retry_scheduled',
                terminal: false,
                nextAttemptAt: '2026-07-29T08:10:00Z',
            },
            retry: {
                scheduled: true,
                attemptCount: 1,
                retryLimit: 3,
                nextAttemptAt: '2026-07-29T08:10:00Z',
            },
            missingArtifacts: ['synthetic_commit'],
        });
        const { rerender } = render(<InspectorContent inspector={retry} />);
        expect(screen.getByText('Retry scheduled')).toBeInTheDocument();
        expect(
            screen.getByText('Missing Synthetic Commit artifact.'),
        ).toBeInTheDocument();

        rerender(
            <InspectorContent
                inspector={inspector({
                    execution: { ...inspector().execution, status: 'failed' },
                    error: {
                        attemptNumber: 1,
                        code: 'development.validation_failed',
                        message: 'Safe validation failure.',
                        retryable: false,
                        retryDelaySeconds: null,
                    },
                })}
            />,
        );
        expect(screen.getByText('Execution failed')).toBeInTheDocument();

        rerender(
            <InspectorContent
                inspector={inspector({
                    execution: {
                        ...inspector().execution,
                        status: 'cancelled',
                        cancellationReason: 'User requested cancellation.',
                    },
                })}
            />,
        );
        expect(screen.getByText('Execution cancelled')).toBeInTheDocument();
    });

    it('renders loading and deferred rescue states accessibly', () => {
        deferredFallback = true;
        const { rerender } = render(
            <DevelopmentExecutionInspectorPage {...props()} />,
        );
        expect(
            screen.getByLabelText('Loading execution inspector'),
        ).toHaveAttribute('aria-busy', 'true');

        deferredFallback = false;
        rerender(
            <DevelopmentExecutionInspectorPage
                {...props({ inspector: undefined })}
            />,
        );
        expect(
            screen.getByText('Inspector data did not load'),
        ).toBeInTheDocument();
    });
});
