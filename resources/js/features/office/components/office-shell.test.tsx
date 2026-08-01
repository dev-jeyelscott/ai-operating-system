import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { officeProjectionFixture } from '@/tests/fixtures/office-projection';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
}));

vi.mock('./office-canvas', () => ({
    default: ({ qualityPreset }: { qualityPreset: string }) => (
        <div
            data-testid="mock-office-canvas"
            data-quality-preset={qualityPreset}
        >
            Mock office Canvas
        </div>
    ),
}));

import { OfficeShell } from './office-shell';

describe('OfficeShell', () => {
    it('does not render the Canvas module before the user requests it', () => {
        render(
            <OfficeShell
                projection={officeProjectionFixture()}
                operationsUrl="/operations"
            />,
        );

        expect(
            screen.queryByTestId('mock-office-canvas'),
        ).not.toBeInTheDocument();

        expect(
            screen.getByRole('link', {
                name: /open accessible dashboard/i,
            }),
        ).toHaveAttribute('href', '/operations');
    });

    it('renders accessible quality controls before WebGL loads', () => {
        render(
            <OfficeShell
                projection={officeProjectionFixture()}
                operationsUrl="/operations"
            />,
        );

        expect(
            screen.getByRole('button', {
                name: 'Low',
            }),
        ).toHaveAttribute('aria-pressed', 'false');

        expect(
            screen.getByRole('button', {
                name: 'Balanced',
            }),
        ).toHaveAttribute('aria-pressed', 'true');

        expect(
            screen.getByRole('button', {
                name: 'High',
            }),
        ).toHaveAttribute('aria-pressed', 'false');
    });

    it('renders the lazy Canvas with balanced quality by default', async () => {
        const user = userEvent.setup();

        render(
            <OfficeShell
                projection={officeProjectionFixture()}
                operationsUrl="/operations"
            />,
        );

        await user.click(
            screen.getByRole('button', {
                name: /load 3d office/i,
            }),
        );

        expect(await screen.findByTestId('mock-office-canvas')).toHaveAttribute(
            'data-quality-preset',
            'balanced',
        );
    });

    it('passes the selected rendering quality to the loaded Canvas', async () => {
        const user = userEvent.setup();

        render(
            <OfficeShell
                projection={officeProjectionFixture()}
                operationsUrl="/operations"
            />,
        );

        await user.click(
            screen.getByRole('button', {
                name: /load 3d office/i,
            }),
        );

        await user.click(
            screen.getByRole('button', {
                name: 'Low',
            }),
        );

        expect(await screen.findByTestId('mock-office-canvas')).toHaveAttribute(
            'data-quality-preset',
            'low',
        );

        expect(
            screen.getByRole('button', {
                name: 'Low',
            }),
        ).toHaveAttribute('aria-pressed', 'true');

        expect(
            screen.getByText(/disables shadows and the decorative grid/i),
        ).toBeInTheDocument();
    });

    it('can switch from low to high without changing projection truth', async () => {
        const user = userEvent.setup();
        const projection = officeProjectionFixture();

        render(
            <OfficeShell projection={projection} operationsUrl="/operations" />,
        );

        await user.click(
            screen.getByRole('button', {
                name: 'Low',
            }),
        );

        await user.click(
            screen.getByRole('button', {
                name: /load 3d office/i,
            }),
        );

        expect(await screen.findByTestId('mock-office-canvas')).toHaveAttribute(
            'data-quality-preset',
            'low',
        );

        await user.click(
            screen.getByRole('button', {
                name: 'High',
            }),
        );

        expect(await screen.findByTestId('mock-office-canvas')).toHaveAttribute(
            'data-quality-preset',
            'high',
        );

        expect(projection.agents[0].officeState).toBe('implementing');
        expect(projection.rooms[2].state).toBe('working');
    });

    it('keeps simulation status visible before WebGL loads', () => {
        render(
            <OfficeShell
                projection={officeProjectionFixture()}
                operationsUrl="/operations"
            />,
        );

        expect(
            screen.getByText('Simulation remains unverified'),
        ).toBeInTheDocument();
    });
});
