import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { NotificationMenu } from '@/components/notification-menu';

const inertia = vi.hoisted(() => ({
    patch: vi.fn(),
    usePoll: vi.fn(),
    pageProps: {
        notifications: {
            unreadCount: 1,
            items: [
                {
                    id: '01K00000000000000000000000',
                    eventId: '01K00000000000000000000001',
                    eventName: 'project.start_requested',
                    projectId: 10,
                    title: 'Project planning started',
                    message: 'Planning has been queued for Example Project.',
                    actionUrl: null,
                    deliveredAt: '2026-07-31T01:00:00+00:00',
                    readAt: null,
                    occurredAt: '2026-07-31T01:00:00+00:00',
                },
            ],
        },
        organizationContext: {
            current: {
                id: 1,
                name: 'Example Organization',
                slug: 'example-organization',
            },
            available: [],
        },
    },
}));

vi.mock('@inertiajs/react', () => ({
    router: {
        patch: inertia.patch,
    },
    usePage: () => ({
        props: inertia.pageProps,
    }),
    usePoll: inertia.usePoll,
}));

vi.mock('@/routes/organizations/notifications', () => ({
    read: {
        url: ({
            organization,
            notificationRecipient,
        }: {
            organization: { slug: string };
            notificationRecipient: string;
        }) =>
            `/organizations/${organization.slug}/notifications/${notificationRecipient}/read`,
    },
}));

describe('NotificationMenu', () => {
    beforeEach(() => {
        inertia.patch.mockReset();
        inertia.usePoll.mockClear();
    });

    it('shows unread state and submits a recipient-scoped read request', async () => {
        const user = userEvent.setup();

        render(<NotificationMenu />);

        expect(
            screen.getByRole('button', {
                name: 'Notifications, 1 unread',
            }),
        ).toBeInTheDocument();

        await user.click(
            screen.getByRole('button', {
                name: 'Notifications, 1 unread',
            }),
        );

        await user.click(screen.getByText('Project planning started'));

        expect(inertia.patch).toHaveBeenCalledWith(
            '/organizations/example-organization/notifications/01K00000000000000000000000/read',
            {},
            expect.objectContaining({
                only: ['notifications'],
                preserveScroll: true,
                preserveState: true,
            }),
        );
    });

    it('polls only the shared notification payload', () => {
        render(<NotificationMenu />);

        expect(inertia.usePoll).toHaveBeenCalledWith(30_000, {
            only: ['notifications'],
            preserveScroll: true,
        });
    });
});
