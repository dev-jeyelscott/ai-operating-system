import { render, screen } from '@testing-library/react';
import type {
    AnchorHTMLAttributes,
    ComponentProps,
    FormHTMLAttributes,
    ReactNode,
} from 'react';
import { describe, expect, it, vi } from 'vitest';

type MockFormProps = FormHTMLAttributes<HTMLFormElement> & {
    children?:
        | ReactNode
        | ((props: {
              processing: boolean;
              errors: Record<string, string>;
          }) => ReactNode);
};

type MockLinkProps = Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & {
    href: string | { url: string };
};

vi.mock('@inertiajs/react', async () => {
    const { forwardRef } = await import('react');

    return {
        Deferred: ({ children }: { children: ReactNode }) => children,
        Form: ({ children, ...attributes }: MockFormProps) => (
            <form {...attributes}>
                {typeof children === 'function'
                    ? children({ processing: false, errors: {} })
                    : children}
            </form>
        ),
        Head: () => null,
        Link: forwardRef<HTMLAnchorElement, MockLinkProps>(function MockLink(
            { children, href, ...attributes },
            reference,
        ) {
            return (
                <a
                    ref={reference}
                    href={typeof href === 'string' ? href : href.url}
                    {...attributes}
                >
                    {children}
                </a>
            );
        }),
    };
});

import RoadmapShow from './show';

type Props = ComponentProps<typeof RoadmapShow>;

function props(overrides: Partial<Props> = {}): Props {
    const base: Props = {
        organization: { id: 1, name: 'AIOS', slug: 'aios' },
        project: {
            id: 2,
            name: 'Planning project',
            slug: 'planning-project',
            status: 'awaiting_roadmap_approval',
        },
        projectUrl: '/organizations/aios/projects/planning-project',
        revisions: [
            {
                id: 3,
                revision: 1,
                contentVersion: 1,
                status: 'awaiting_approval',
                readiness: 'ready',
                generatedAt: '2026-07-28T00:00:00Z',
            },
        ],
        diagnostics: [],
        roadmap: {
            id: 3,
            revision: 1,
            contentVersion: 1,
            candidateFingerprint: 'a'.repeat(64),
            outputFingerprint: 'a'.repeat(64),
            status: 'awaiting_approval',
            isLatest: true,
            readiness: 'ready',
            readinessReasons: [],
            goal: 'Deliver a traceable roadmap',
            scope: ['Planning'],
            assumptions: [],
            constraints: ['Approved context only'],
            definitionOfDone: ['Approval recorded'],
            requiredApprovals: ['roadmap'],
            documentSummary: 'One approved source.',
            documentInventory: [],
            architectureConcerns: [],
            securityConcerns: [],
            criticalPath: ['task-one'],
            comparison: {
                generatedGoal: 'Deliver a traceable roadmap',
                candidateGoal: 'Deliver a traceable roadmap',
                approvedGoal: null,
                generatedFingerprint: 'a'.repeat(64),
                candidateFingerprint: 'a'.repeat(64),
                approvedFingerprint: null,
            },
            approval: { id: '01TEST', status: 'pending', reason: null },
            phases: [
                {
                    id: 4,
                    stableId: 'phase-one',
                    name: 'Discovery',
                    milestones: [],
                },
            ],
            tasks: [
                {
                    id: 5,
                    stableId: 'task-one',
                    title: 'Trace requirements',
                    objective: 'Map requirements to source versions.',
                    phaseId: 4,
                    phaseName: 'Discovery',
                    milestoneName: 'Ready',
                    ticketType: 'feature',
                    scope: { included: ['Planning'], excluded: [] },
                    acceptanceCriteria: [
                        {
                            stable_id: 'criterion-one',
                            description: 'Every requirement has a source.',
                            source_references: [],
                        },
                    ],
                    evidenceRequirements: ['Human review'],
                    priority: 'high',
                    risk: 'medium',
                    reasoningLevel: 'medium',
                    reasoning: 'Approved documents are authoritative.',
                    logicalAgent: 'project_manager',
                    estimatedComplexity: 3,
                    humanApprovalRequired: true,
                    criticalPathRank: 3,
                    criticalPathPosition: 1,
                    isCriticalPath: true,
                    dependencies: [],
                    notionMapping: null,
                    traceability: [
                        {
                            criterionStableId: 'criterion-one',
                            documentId: 6,
                            documentVersionId: 7,
                            documentVersion: 2,
                            checksumSha256: 'b'.repeat(64),
                            documentName: 'Product requirements',
                        },
                    ],
                },
            ],
            reverseTraceability: [
                {
                    documentVersionId: 7,
                    documentName: 'Product requirements',
                    documentVersion: 2,
                    tasks: [{ taskId: 5, criterionStableId: 'criterion-one' }],
                },
            ],
            edits: [],
        },
        selectedPhaseId: null,
        selectedTaskId: 5,
        permissions: {
            edit: true,
            decide: true,
            regenerate: true,
            publish: true,
        },
        notionPublication: {
            readiness: false,
            schemaReadiness: 'not_configured',
            dataSourceName: null,
            dataSourceId: null,
            summary: null,
        },
        notionConflicts: [],
        actionIdempotencyKey: 'test-idempotency',
    };

    return { ...base, ...overrides };
}

describe('RoadmapShow', () => {
    it('shows the task-level Notion mapping and page link', () => {
        render(
            <RoadmapShow
                {...props({
                    roadmap: {
                        ...props().roadmap!,
                        tasks: [
                            {
                                ...props().roadmap!.tasks[0],
                                notionMapping: {
                                    mappingId: 8,
                                    externalKey: 'notion:p2:r1:ttask-one',
                                    pageUrl: 'https://www.notion.so/task-one',
                                    state: 'synchronized',
                                    reconciliationState: 'in_sync',
                                    retryable: false,
                                },
                            },
                        ],
                    },
                })}
            />,
        );

        expect(screen.getByText('notion:p2:r1:ttask-one')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Open in Notion' }),
        ).toHaveAttribute('href', 'https://www.notion.so/task-one');
        expect(
            screen.getByRole('button', { name: 'Retry this task' }),
        ).toBeDisabled();
    });

    it('submits only the selected retryable failed task', () => {
        render(
            <RoadmapShow
                {...props({
                    roadmap: {
                        ...props().roadmap!,
                        status: 'approved',
                        isLatest: true,
                        tasks: [
                            {
                                ...props().roadmap!.tasks[0],
                                notionMapping: {
                                    mappingId: 8,
                                    externalKey: 'notion:p2:r1:ttask-one',
                                    pageUrl: null,
                                    state: 'failed',
                                    reconciliationState: null,
                                    retryable: true,
                                },
                            },
                        ],
                    },
                    notionPublication: {
                        readiness: true,
                        schemaReadiness: 'ready',
                        dataSourceName: 'Tickets',
                        dataSourceId: 'source-1',
                        summary: null,
                    },
                })}
            />,
        );

        expect(
            screen.getByRole('button', { name: 'Retry this task' }),
        ).toBeEnabled();
        expect(screen.getByDisplayValue('5')).toHaveAttribute(
            'name',
            'task_ids[]',
        );
    });

    it('shows an explicit accepted-external conflict decision form', () => {
        render(
            <RoadmapShow
                {...props({
                    roadmap: {
                        ...props().roadmap!,
                        status: 'approved',
                    },
                    notionConflicts: [
                        {
                            id: 9,
                            mappingId: 4,
                            currentFingerprint: 'b'.repeat(64),
                            externalFingerprint: 'b'.repeat(64),
                            state: 'open',
                            decision: null,
                            decisionReason: null,
                        },
                    ],
                })}
            />,
        );

        expect(
            screen.getByRole('heading', {
                name: 'External change decisions',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', {
                name: 'Accept as new revision',
            }),
        ).toBeEnabled();
        expect(
            screen.getByRole('button', {
                name: 'Retain internal and republish',
            }),
        ).toBeEnabled();
        expect(
            screen.getByRole('button', {
                name: 'Defer and block writes',
            }),
        ).toBeEnabled();
    });

    it('renders an accessible empty state', () => {
        render(<RoadmapShow {...props({ roadmap: null, revisions: [] })} />);

        expect(
            screen.getByRole('heading', { name: 'No roadmap yet' }),
        ).toBeInTheDocument();
    });

    it('shows task traceability, reasoning, and approval actions', () => {
        render(<RoadmapShow {...props()} />);

        expect(
            screen.getByRole('heading', { name: 'Roadmap inspection' }),
        ).toBeInTheDocument();
        expect(screen.getAllByText('Product requirements v2')).toHaveLength(2);
        expect(
            screen.getByText('Approved documents are authoritative.'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Approve roadmap' }),
        ).toBeInTheDocument();
        expect(screen.getByLabelText('Regeneration feedback')).toBeRequired();
    });

    it('disables publication until a Notion data source is ready', () => {
        render(<RoadmapShow {...props()} />);

        expect(screen.getByText('Not configured')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Publish to Notion' }),
        ).toBeDisabled();
    });

    it('reports an incompatible Notion ticket schema without enabling publication', () => {
        render(
            <RoadmapShow
                {...props({
                    notionPublication: {
                        readiness: false,
                        schemaReadiness: 'incompatible',
                        dataSourceName: 'Tickets',
                        dataSourceId: 'source-1',
                        summary: null,
                    },
                })}
            />,
        );

        expect(screen.getByText('Incompatible')).toBeInTheDocument();
        expect(
            screen.getByText(
                'The selected Notion data source is missing required ticket properties.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Publish to Notion' }),
        ).toBeDisabled();
    });

    it('shows completed publication counts and enables approved publication', () => {
        const current = props();
        render(
            <RoadmapShow
                {...current}
                roadmap={{ ...current.roadmap!, status: 'approved' }}
                notionPublication={{
                    readiness: true,
                    schemaReadiness: 'ready',
                    dataSourceName: 'Tickets',
                    dataSourceId: 'source-1',
                    summary: {
                        createdCount: 2,
                        updatedCount: 1,
                        skippedCount: 3,
                        failedCount: 0,
                        conflictedCount: 0,
                        completedAt: '2026-07-28T00:00:00Z',
                        diagnostics: [],
                    },
                }}
            />,
        );

        expect(screen.getByText('Tickets')).toBeInTheDocument();
        expect(
            screen.getByText(/2 created, 1 updated, 3 skipped/),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Publish to Notion' }),
        ).toBeEnabled();
        expect(
            screen.getByRole('button', { name: 'Retry failed' }),
        ).toBeDisabled();
    });

    it('shows sanitized publication diagnostics from the latest summary', () => {
        const current = props();
        render(
            <RoadmapShow
                {...current}
                roadmap={{ ...current.roadmap!, status: 'approved' }}
                notionPublication={{
                    readiness: true,
                    schemaReadiness: 'ready',
                    dataSourceName: 'Tickets',
                    dataSourceId: 'source-1',
                    summary: {
                        createdCount: 0,
                        updatedCount: 0,
                        skippedCount: 1,
                        failedCount: 1,
                        conflictedCount: 0,
                        completedAt: '2026-07-28T00:00:00Z',
                        diagnostics: [
                            'A hard dependency has no successful external mapping.',
                        ],
                    },
                }}
            />,
        );

        expect(
            screen.getByText(
                'A hard dependency has no successful external mapping.',
            ),
        ).toBeInTheDocument();
    });

    it('renders blocking diagnostics', () => {
        const current = props();
        render(
            <RoadmapShow
                {...current}
                roadmap={{
                    ...current.roadmap!,
                    status: 'blocked',
                    readiness: 'blocked',
                    readinessReasons: ['Dependency cycle detected.'],
                }}
            />,
        );

        expect(
            screen.getByText('Dependency cycle detected.'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Approve roadmap' }),
        ).not.toBeInTheDocument();
    });

    it('renders document inventory, comparison, and stale state', () => {
        const current = props();
        render(
            <RoadmapShow
                {...current}
                roadmap={{
                    ...current.roadmap!,
                    isLatest: false,
                    documentInventory: [
                        {
                            document_id: 6,
                            document_version_id: 7,
                            version: 2,
                            checksum_sha256: 'b'.repeat(64),
                            classification: 'approved_project_context',
                            summary: 'Approved product requirements.',
                        },
                    ],
                }}
            />,
        );

        expect(screen.getByText('Stale roadmap revision')).toBeInTheDocument();
        expect(
            screen.getByText('Approved product requirements.', {
                exact: false,
            }),
        ).toBeInTheDocument();
        expect(screen.getByText('Current candidate')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Approve roadmap' }),
        ).not.toBeInTheDocument();
    });

    it('renders an explicit read-only state without action controls', () => {
        render(
            <RoadmapShow
                {...props({
                    permissions: {
                        edit: false,
                        decide: false,
                        regenerate: false,
                        publish: false,
                    },
                })}
            />,
        );

        expect(
            screen.getByText('Read-only roadmap access'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Approve roadmap' }),
        ).not.toBeInTheDocument();
    });

    it('shows a persisted diagnostic when planning cannot publish a roadmap', () => {
        render(
            <RoadmapShow
                {...props({
                    roadmap: null,
                    revisions: [],
                    diagnostics: [
                        {
                            code: 'planning.invalid_result',
                            category: 'deterministic_blocker',
                            message: 'Dependency cycle detected.',
                            createdAt: '2026-07-28T00:00:00Z',
                        },
                    ],
                })}
            />,
        );

        expect(
            screen.getByText('Dependency cycle detected.'),
        ).toBeInTheDocument();
    });
});
