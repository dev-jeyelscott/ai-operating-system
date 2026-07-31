import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { OfficeNavigation } from '@/features/office/components/office-navigation';
import { officeProjectionFixture } from '@/tests/fixtures/office-projection';

describe('OfficeNavigation', () => {
    it('renders every authoritative room as a keyboard button', () => {
        render(
            <OfficeNavigation
                rooms={officeProjectionFixture().rooms}
                selectedRoom="lobby"
                onSelectRoom={vi.fn()}
            />,
        );

        expect(
            screen.getByRole('button', {
                name: /lobby/i,
            }),
        ).toHaveAttribute('aria-pressed', 'true');

        expect(
            screen.getByRole('button', {
                name: /planning room/i,
            }),
        ).toBeInTheDocument();

        expect(
            screen.getByRole('button', {
                name: /development floor/i,
            }),
        ).toBeInTheDocument();

        expect(
            screen.getByRole('button', {
                name: /qa laboratory/i,
            }),
        ).toBeInTheDocument();

        expect(
            screen.getByRole('button', {
                name: /approval room/i,
            }),
        ).toBeInTheDocument();

        expect(
            screen.getByRole('button', {
                name: /operations area/i,
            }),
        ).toBeInTheDocument();

        expect(
            screen.getByRole('button', {
                name: /completed work/i,
            }),
        ).toBeInTheDocument();
    });

    it('reports room selection without mutating workflow data', async () => {
        const user = userEvent.setup();
        const onSelectRoom = vi.fn();

        render(
            <OfficeNavigation
                rooms={officeProjectionFixture().rooms}
                selectedRoom="lobby"
                onSelectRoom={onSelectRoom}
            />,
        );

        await user.click(
            screen.getByRole('button', {
                name: /qa laboratory/i,
            }),
        );

        expect(onSelectRoom).toHaveBeenCalledWith('qa_laboratory');
    });
});
