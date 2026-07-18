import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { OrganizationSwitcher } from './organization-switcher';

const mocks = vi.hoisted(() => ({
    visit: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    router: {
        visit: mocks.visit,
    },
    usePage: () => ({
        props: {
            organizationContext: {
                current: {
                    id: 1,
                    name: 'Alpha',
                    slug: 'alpha',
                },
                available: [
                    {
                        id: 1,
                        name: 'Alpha',
                        slug: 'alpha',
                    },
                    {
                        id: 2,
                        name: 'Beta',
                        slug: 'beta',
                    },
                ],
            },
        },
    }),
}));

vi.mock('@/routes/organizations/current', () => ({
    update: ({ organization }: { organization: string }) => ({
        method: 'put',
        url: `/organizations/${organization}/current`,
    }),
}));

vi.mock('@/components/ui/dropdown-menu', () => ({
    DropdownMenu: ({ children }: { children: ReactNode }) => (
        <div>{children}</div>
    ),
    DropdownMenuTrigger: ({ children }: { children: ReactNode }) => (
        <div>{children}</div>
    ),
    DropdownMenuContent: ({ children }: { children: ReactNode }) => (
        <div>{children}</div>
    ),
    DropdownMenuLabel: ({ children }: { children: ReactNode }) => (
        <div>{children}</div>
    ),
    DropdownMenuSeparator: () => <hr />,
    DropdownMenuItem: ({
        children,
        disabled,
        onSelect,
    }: {
        children: ReactNode;
        disabled?: boolean;
        onSelect?: () => void;
    }) => (
        <button type="button" disabled={disabled} onClick={onSelect}>
            {children}
        </button>
    ),
}));

describe('OrganizationSwitcher', () => {
    beforeEach(() => {
        mocks.visit.mockReset();
    });

    it('submits the selected organization through the server route', async () => {
        const user = userEvent.setup();

        render(<OrganizationSwitcher />);

        await user.click(
            screen.getByRole('button', {
                name: /beta/i,
            }),
        );

        expect(mocks.visit).toHaveBeenCalledWith(
            {
                method: 'put',
                url: '/organizations/beta/current',
            },
            expect.objectContaining({
                preserveScroll: true,
            }),
        );
    });
});
