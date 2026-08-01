import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { officeProjectionFixture } from '@/tests/fixtures/office-projection';
import { OfficeNavigation } from './office-navigation';

describe('OfficeNavigation', () => {
    it('keeps one room control in the tab sequence', () => {
        const projection = officeProjectionFixture();

        render(
            <OfficeNavigation
                rooms={projection.rooms}
                selectedRoom="lobby"
                onSelectRoom={vi.fn()}
            />,
        );

        const buttons = screen.getAllByRole('button');

        expect(buttons.filter((button) => button.tabIndex === 0)).toHaveLength(
            1,
        );

        expect(screen.getByRole('button', { name: /lobby/i })).toHaveAttribute(
            'tabindex',
            '0',
        );
    });

    it('moves focus and selection with arrow keys', async () => {
        const user = userEvent.setup();
        const onSelectRoom = vi.fn();
        const projection = officeProjectionFixture();

        render(
            <OfficeNavigation
                rooms={projection.rooms}
                selectedRoom="lobby"
                onSelectRoom={onSelectRoom}
            />,
        );

        const lobby = screen.getByRole('button', {
            name: /lobby/i,
        });

        lobby.focus();
        await user.keyboard('{ArrowRight}');

        expect(onSelectRoom).toHaveBeenCalledWith('planning_room');

        expect(
            screen.getByRole('button', {
                name: /planning room/i,
            }),
        ).toHaveFocus();
    });

    it('supports Home and End', async () => {
        const user = userEvent.setup();
        const onSelectRoom = vi.fn();
        const projection = officeProjectionFixture();

        render(
            <OfficeNavigation
                rooms={projection.rooms}
                selectedRoom="planning_room"
                onSelectRoom={onSelectRoom}
            />,
        );

        const planning = screen.getByRole('button', {
            name: /planning room/i,
        });

        planning.focus();
        await user.keyboard('{End}');
        expect(onSelectRoom).toHaveBeenLastCalledWith('archive');

        await user.keyboard('{Home}');
        expect(onSelectRoom).toHaveBeenLastCalledWith('lobby');
    });
});
