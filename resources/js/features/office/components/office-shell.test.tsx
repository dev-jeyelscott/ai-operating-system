import {
    render,
    screen,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import {
    describe,
    expect,
    it,
    vi,
} from 'vitest';
import { officeProjectionFixture } from '@/tests/fixtures/office-projection';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        children,
        href,
    }: {
        children: ReactNode;
        href: string;
    }) => <a href={href}>{children}</a>,
}));

vi.mock('./office-canvas', () => ({
    default: () => (
        <div data-testid="mock-office-canvas">
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

    it('renders the lazy Canvas after an explicit user action', async () => {
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

        expect(
            await screen.findByTestId('mock-office-canvas'),
        ).toBeInTheDocument();
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
