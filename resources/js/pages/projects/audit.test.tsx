import { render, screen, within } from '@testing-library/react';
import type {
    AnchorHTMLAttributes,
    ComponentProps,
    FormHTMLAttributes,
    ReactNode,
} from 'react';
import { describe, expect, it, vi } from 'vitest';

type MockFormProps = FormHTMLAttributes<HTMLFormElement> & {
    children?: ReactNode;
};

type MockLinkProps = AnchorHTMLAttributes<HTMLAnchorElement> & {
    href: string;
    preserveScroll?: boolean;
};

vi.mock('@inertiajs/react', async () => {
    const { forwardRef } = await import('react');

    return {
        Form: ({ children, ...attributes }: MockFormProps) => (
            <form {...attributes}>{children}</form>
        ),
        Head: () => null,
        Link: forwardRef<HTMLAnchorElement, MockLinkProps>(function MockLink(
            { children, href, preserveScroll, ...attributes },
            reference,
        ) {
            return (
                <a
                    ref={reference}
                    href={href}
                    data-preserve-scroll={preserveScroll ? 'true' : undefined}
                    {...attributes}
                >
                    {children}
                </a>
            );
        }),
    };
});

import ProjectAuditPage from './audit';

type PageProps = ComponentProps<typeof ProjectAuditPage>;

/**
 * Build a complete audit page property fixture.
 */
function createPageProps(overrides: Partial<PageProps> = {}): PageProps {
    const execution = {
        id: '01K123456789PROJECTAUDIT',
        capability: 'planning',
        logicalRole: 'project_manager',
        status: {
            value: 'failed',
            label: 'Failed',
        },
        requestedReasoning: 'high',
        attemptCount: 1,
        retryCount: 0,
        retryLimit: 2,
        artifactCount: 1,
        errorCount: 1,
        estimatedCost: '0.01250000',
        actualCost: null,
        costCurrency: 'USD',
        latestProvider: 'simulation',
        isSimulated: true,
        correlationId: 'correlation-audit-test',
        startedAt: '2026-07-27T10:00:00+08:00',
        finishedAt: '2026-07-27T10:01:00+08:00',
        nextAttemptAt: null,
        createdAt: '2026-07-27T09:59:00+08:00',
    };

    const props: PageProps = {
        organization: {
            id: 1,
            name: 'AIOS Engineering',
            slug: 'aios-engineering',
        },
        project: {
            id: 10,
            name: 'AI Operating System',
            slug: 'ai-operating-system',
            status: {
                value: 'planning',
                label: 'Planning',
            },
        },
        auditUrl:
            '/organizations/aios-engineering/projects/ai-operating-system/audit',
        projectUrl:
            '/organizations/aios-engineering/projects/ai-operating-system',
        filters: {
            execution: execution.id,
            eventType: null,
            subjectType: null,
            subjectId: null,
            correlationId: null,
            causationId: null,
            order: 'newest_first',
            perPage: 25,
        },
        filterOptions: {
            eventTypes: [
                {
                    value: 'execution.attempt_failed',
                    label: 'Execution Attempt Failed',
                },
            ],
            subjectTypes: [
                {
                    value: 'execution',
                    label: 'Execution',
                },
            ],
        },
        executions: [execution],
        selectedExecution: {
            ...execution,
            idempotencyKey: 'audit-test-idempotency-key',
            timeoutSeconds: 300,
            cancellationReason: null,
            cancelRequestedAt: null,
            cancelledAt: null,
            attempts: [
                {
                    id: 1,
                    attemptNumber: 1,
                    status: {
                        value: 'failed',
                        label: 'Failed',
                    },
                    provider: 'simulation',
                    modelIdentifier: null,
                    requestedReasoning: 'high',
                    effectiveReasoning: 'simulated',
                    reasoningSource: 'ticket_policy',
                    reasoningEscalationReason: null,
                    simulationMode: 'failure_path',
                    simulationSeed: 'audit-test-seed',
                    reportedState: 'failed',
                    observedState: 'failed',
                    actualState: 'unverified',
                    confidence: '0.7500',
                    estimatedCost: '0.01250000',
                    actualCost: null,
                    costCurrency: 'USD',
                    error: {
                        code: 'provider.failed',
                        message:
                            'The simulation provider returned a safe failure.',
                        retryable: true,
                        retryDelaySeconds: 30,
                    },
                    deadlineAt: null,
                    heartbeatAt: null,
                    startedAt: '2026-07-27T10:00:00+08:00',
                    finishedAt: '2026-07-27T10:01:00+08:00',
                },
            ],
            artifacts: [
                {
                    id: '01KARTIFACTAUDITTEST',
                    type: 'planning_result',
                    name: 'Simulated planning result',
                    provider: 'simulation',
                    externalReference: null,
                    mediaType: 'application/json',
                    checksumSha256: null,
                    byteSize: null,
                    simulationMode: 'failure_path',
                    simulationSeed: 'audit-test-seed',
                    assumptions: [],
                    confidence: '0.7500',
                    evidenceStillRequired: true,
                    evidenceCount: 0,
                    actualState: 'unverified',
                    isSimulated: true,
                    createdAt: '2026-07-27T10:01:00+08:00',
                },
            ],
        },
        timeline: {
            data: [],
            perPage: 25,
            nextCursor: null,
            previousCursor: null,
        },
    };

    return {
        ...props,
        ...overrides,
    };
}

describe('ProjectAuditPage', () => {
    it('exports a page component', () => {
        expect(ProjectAuditPage).toBeTypeOf('function');
    });

    it('renders accessible empty execution and timeline states', () => {
        render(
            <ProjectAuditPage
                {...createPageProps({
                    executions: [],
                    selectedExecution: null,
                    timeline: {
                        data: [],
                        perPage: 25,
                        nextCursor: null,
                        previousCursor: null,
                    },
                })}
            />,
        );

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'Execution and audit',
            }),
        ).toBeInTheDocument();

        expect(
            screen.getByRole('heading', {
                level: 3,
                name: 'No executions yet',
            }),
        ).toBeInTheDocument();

        expect(
            screen.getByRole('heading', {
                level: 3,
                name: 'Nothing to inspect',
            }),
        ).toBeInTheDocument();

        expect(
            screen.getByRole('heading', {
                level: 3,
                name: 'No matching audit events',
            }),
        ).toBeInTheDocument();
    });

    it('marks simulated artifacts unverified and displays safe errors', () => {
        render(<ProjectAuditPage {...createPageProps()} />);

        const artifactName = screen.getByText('Simulated planning result');
        const artifact = artifactName.closest('li');

        expect(artifact).not.toBeNull();

        expect(
            within(artifact as HTMLElement).getByText('Simulated / Unverified'),
        ).toBeInTheDocument();

        expect(
            within(artifact as HTMLElement).getByText('Unverified'),
        ).toBeInTheDocument();

        expect(screen.getByRole('alert')).toHaveTextContent('provider.failed');

        expect(screen.getByRole('alert')).toHaveTextContent(
            'The simulation provider returned a safe failure.',
        );
    });

    it('does not render raw artifact bodies or raw audit metadata', () => {
        const props = createPageProps({
            timeline: {
                data: [
                    {
                        sequence: 1,
                        eventId: '01KAUDITEVENTTEST',
                        eventType: {
                            value: 'execution.attempt_failed',
                            label: 'Execution Attempt Failed',
                        },
                        actor: {
                            type: 'system',
                            id: 'audit-page-test',
                        },
                        subject: {
                            type: 'execution',
                            id: '01K123456789PROJECTAUDIT',
                        },
                        executionId: '01K123456789PROJECTAUDIT',
                        correlationId: 'correlation-audit-test',
                        causationId: null,
                        schemaVersion: 1,
                        occurredAt: '2026-07-27T10:01:00+08:00',
                    },
                ],
                perPage: 25,
                nextCursor: null,
                previousCursor: null,
            },
        });

        const selectedExecution = props.selectedExecution;

        expect(selectedExecution).not.toBeNull();

        if (selectedExecution === null) {
            return;
        }

        Object.assign(selectedExecution.artifacts[0], {
            body: 'RAW_ARTIFACT_BODY_MUST_NOT_RENDER',
        });

        Object.assign(props.timeline.data[0], {
            metadata: {
                secret: 'RAW_AUDIT_METADATA_MUST_NOT_RENDER',
            },
        });

        render(<ProjectAuditPage {...props} />);

        expect(
            screen.queryByText('RAW_ARTIFACT_BODY_MUST_NOT_RENDER'),
        ).not.toBeInTheDocument();

        expect(
            screen.queryByText('RAW_AUDIT_METADATA_MUST_NOT_RENDER'),
        ).not.toBeInTheDocument();
    });
});
