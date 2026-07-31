import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { AgentStatusPanel } from '@/features/office/components/agent-status-panel';
import { officeProjectionFixture } from '@/tests/fixtures/office-projection';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
}));

describe('AgentStatusPanel', () => {
    it('shows authoritative state and simulation labels', () => {
        render(
            <AgentStatusPanel
                agents={officeProjectionFixture().agents}
                selectedRoom="lobby"
                onSelectRoom={vi.fn()}
            />,
        );

        expect(screen.getByText('Frontend Engineer')).toBeInTheDocument();

        expect(screen.getByText('Implementing')).toBeInTheDocument();

        expect(screen.getByText('Simulated')).toBeInTheDocument();

        expect(screen.getByText('Unverified')).toBeInTheDocument();

        expect(
            screen.getByRole('link', {
                name: /open context/i,
            }),
        ).toHaveAttribute('href', '/development/executions/1');
    });

    it('focuses the agent room without changing agent state', async () => {
        const user = userEvent.setup();
        const onSelectRoom = vi.fn();

        render(
            <AgentStatusPanel
                agents={officeProjectionFixture().agents}
                selectedRoom="lobby"
                onSelectRoom={onSelectRoom}
            />,
        );

        await user.click(
            screen.getByRole('button', {
                name: /focus room/i,
            }),
        );

        expect(onSelectRoom).toHaveBeenCalledWith('development_floor');

        expect(officeProjectionFixture().agents[0].officeState).toBe(
            'implementing',
        );
    });
});
