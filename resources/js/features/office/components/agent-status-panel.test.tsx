import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { officeProjectionFixture } from '@/tests/fixtures/office-projection';
import { AgentStatusPanel } from './agent-status-panel';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
}));

describe('AgentStatusPanel', () => {
    it('provides an inspect action for every agent', () => {
        const projection = officeProjectionFixture();

        render(
            <AgentStatusPanel
                agents={projection.agents}
                selectedRoom="lobby"
                selectedAgentId={null}
                onSelectRoom={vi.fn()}
                onInspectAgent={vi.fn()}
            />,
        );

        expect(
            screen.getAllByRole('button', {
                name: /inspect agent/i,
            }),
        ).toHaveLength(projection.agents.length);
    });

    it('passes the exact trigger for focus restoration', async () => {
        const user = userEvent.setup();
        const onInspectAgent = vi.fn();
        const projection = officeProjectionFixture();

        render(
            <AgentStatusPanel
                agents={projection.agents}
                selectedRoom="lobby"
                selectedAgentId={null}
                onSelectRoom={vi.fn()}
                onInspectAgent={onInspectAgent}
            />,
        );

        const trigger = screen.getAllByRole('button', {
            name: /inspect agent/i,
        })[0];

        await user.click(trigger);

        expect(onInspectAgent).toHaveBeenCalledWith(
            expect.any(String),
            trigger,
        );
    });
});
