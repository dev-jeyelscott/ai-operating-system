import { render, screen, within } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

const inertiaMocks = vi.hoisted(() => ({
    usePoll: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    usePoll: inertiaMocks.usePoll,
}));

import ProjectUsageView from './usage';

type UsageData = ComponentProps<typeof ProjectUsageView>['usage'];

/**
 * Build a stable usage payload with separately classified costs.
 */
function usageData(): UsageData {
    return {
        metadata: {
            asOf: '2026-08-01T10:00:00+08:00',
            fingerprint: 'b'.repeat(64),
        },
        summary: {
            attempts: 4,
            executions: 2,
            currencies: [
                {
                    currency: 'USD',
                    attempts: 4,
                    executions: 2,
                    estimatedSimulationCost: '1.25000000',
                    estimatedProviderCost: '4.50000000',
                    actualProviderCost: '2.00000000',
                },
            ],
        },
        byProvider: [
            {
                provider: 'simulation',
                currency: 'USD',
                attempts: 2,
                executions: 1,
                estimatedSimulationCost: '1.25000000',
                estimatedProviderCost: '0.00000000',
                actualProviderCost: '0.00000000',
            },
            {
                provider: 'openai',
                currency: 'USD',
                attempts: 2,
                executions: 1,
                estimatedSimulationCost: '0.00000000',
                estimatedProviderCost: '4.50000000',
                actualProviderCost: '2.00000000',
            },
        ],
        byRole: [
            {
                role: 'backend_engineer',
                currency: 'USD',
                attempts: 4,
                executions: 2,
                estimatedSimulationCost: '1.25000000',
                estimatedProviderCost: '4.50000000',
                actualProviderCost: '2.00000000',
            },
        ],
        byReasoning: [
            {
                reasoning: 'medium',
                currency: 'USD',
                attempts: 4,
                executions: 2,
                estimatedSimulationCost: '1.25000000',
                estimatedProviderCost: '4.50000000',
                actualProviderCost: '2.00000000',
            },
        ],
        dataQuality: {
            simulationActualCostRecords: 0,
            missingCurrencyRecords: 0,
        },
    };
}

/**
 * Render the project usage page.
 */
function renderUsageView(usage: UsageData = usageData()) {
    return render(
        <ProjectUsageView
            project={{
                id: 10,
                name: 'AI Operating System',
                slug: 'ai-operating-system',
            }}
            operationsUrl="/organizations/1/projects/10/operations"
            recoveryUrl="/organizations/1/projects/10/operations/recovery"
            usage={usage}
        />,
    );
}

describe('ProjectUsageView', () => {
    it('keeps estimated and actual costs visibly separated', () => {
        renderUsageView();

        expect(
            screen.getByRole('heading', {
                name: 'Project usage and costs',
            }),
        ).toBeInTheDocument();

        expect(
            screen.getByText('Simulation remains an estimate'),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                /simulation does not create actual provider charges/i,
            ),
        ).toBeInTheDocument();

        const costSummary = screen
            .getByRole('heading', {
                name: 'Cost summary by currency',
            })
            .closest('section');

        expect(costSummary).not.toBeNull();

        if (!costSummary) {
            throw new Error('Expected the currency cost summary to render.');
        }

        expect(
            within(costSummary).getByText('Estimated simulation'),
        ).toBeInTheDocument();

        expect(
            within(costSummary).getByText('Estimated provider'),
        ).toBeInTheDocument();

        expect(
            within(costSummary).getByText('Actual provider'),
        ).toBeInTheDocument();

        expect(
            within(costSummary).getByText(
                'Planning estimate only; not billed.',
            ),
        ).toBeInTheDocument();

        expect(
            within(costSummary).getByText(
                'Recorded non-simulation provider cost.',
            ),
        ).toBeInTheDocument();
    });

    it('renders semantic cost breakdown tables', () => {
        renderUsageView();

        const providerCaption = screen.getByText(
            /usage by provider with separated estimated and actual cost fields/i,
        );

        const providerTable = providerCaption.closest('table');

        expect(providerTable).toBeInstanceOf(HTMLTableElement);

        if (!providerTable) {
            throw new Error('Expected the provider usage table to render.');
        }

        expect(within(providerTable).getByText('Provider')).toBeInTheDocument();

        expect(
            within(providerTable).getByText('Estimated simulation'),
        ).toBeInTheDocument();

        expect(
            within(providerTable).getByText('Actual provider'),
        ).toBeInTheDocument();

        expect(
            within(providerTable).getByText('Simulation'),
        ).toBeInTheDocument();

        expect(within(providerTable).getByText('Openai')).toBeInTheDocument();
    });

    it('surfaces invalid cost classifications for review', () => {
        const usage = usageData();

        usage.dataQuality = {
            simulationActualCostRecords: 2,
            missingCurrencyRecords: 1,
        };

        renderUsageView(usage);

        expect(screen.getByText('Cost data needs review')).toBeInTheDocument();

        expect(
            screen.getByText(
                /2 simulation attempt\(s\) contain actual cost and 1 cost record\(s\) have no currency/i,
            ),
        ).toBeInTheDocument();
    });
});
