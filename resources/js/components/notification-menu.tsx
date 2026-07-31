import { router, usePage, usePoll } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type { InAppNotification } from '@/types';

/**
 * Displays the current user's persistent, recipient-scoped notification inbox.
 */
export function NotificationMenu() {
    const { notifications } = usePage().props;

    /*
     * Poll only the notification prop. Inertia reloads preserve component
     * state and scroll position automatically and stop when unmounted.
     */
    usePoll(30_000, {
        only: ['notifications'],
    });

    const unreadLabel =
        notifications.unreadCount > 99
            ? '99+'
            : String(notifications.unreadCount);

    /**
     * Submit the server-owned open command.
     *
     * The server marks the notification read and returns a 303 redirect to the
     * exact authorized context. The client never resolves destination metadata.
     */
    const openNotification = (
        notification: InAppNotification,
    ): void => {
        router.post(
            notification.actionUrl,
            {},
            {
                preserveScroll: false,
                preserveState: false,
            },
        );
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className="relative inline-flex size-9 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                    aria-label={`Notifications, ${notifications.unreadCount} unread`}
                >
                    <Bell className="size-5" aria-hidden="true" />

                    {notifications.unreadCount > 0 && (
                        <span
                            className="absolute -top-1 -right-1 flex min-w-5 items-center justify-center rounded-full bg-destructive px-1 text-[10px] leading-5 font-semibold text-destructive-foreground"
                            aria-hidden="true"
                        >
                            {unreadLabel}
                        </span>
                    )}
                </button>
            </DropdownMenuTrigger>

            <DropdownMenuContent
                align="end"
                className="w-[min(24rem,calc(100vw-2rem))] p-0"
            >
                <DropdownMenuLabel className="flex items-center justify-between px-4 py-3">
                    <span>Notifications</span>

                    <span
                        className="text-xs font-normal text-muted-foreground"
                        aria-live="polite"
                    >
                        {notifications.unreadCount} unread
                    </span>
                </DropdownMenuLabel>

                <DropdownMenuSeparator className="m-0" />

                {notifications.items.length === 0 ? (
                    <div className="px-4 py-8 text-center">
                        <Bell
                            className="mx-auto size-6 text-muted-foreground"
                            aria-hidden="true"
                        />

                        <p className="mt-2 text-sm font-medium">
                            No notifications
                        </p>

                        <p className="mt-1 text-xs text-muted-foreground">
                            Workflow updates requiring your attention will
                            appear here.
                        </p>
                    </div>
                ) : (
                    <div className="max-h-[24rem] overflow-y-auto p-1">
                        {notifications.items.map((notification) => (
                            <DropdownMenuItem
                                key={notification.id}
                                className="items-start gap-3 px-3 py-3"
                                onSelect={() => {
                                    openNotification(notification);
                                }}
                            >
                                <span
                                    className={cn(
                                        'mt-1.5 size-2 shrink-0 rounded-full',
                                        notification.readAt === null
                                            ? 'bg-primary'
                                            : 'bg-transparent',
                                    )}
                                    aria-hidden="true"
                                />

                                <span className="min-w-0 flex-1">
                                    <span
                                        className={cn(
                                            'block truncate text-sm',
                                            notification.readAt === null
                                                ? 'font-semibold'
                                                : 'font-medium',
                                        )}
                                    >
                                        {notification.title}
                                    </span>

                                    <span className="mt-1 line-clamp-2 block text-xs leading-5 text-muted-foreground">
                                        {notification.message}
                                    </span>

                                    <time
                                        dateTime={notification.occurredAt}
                                        className="mt-1 block text-[11px] text-muted-foreground"
                                    >
                                        {formatNotificationTime(
                                            notification.occurredAt,
                                        )}
                                    </time>
                                </span>
                            </DropdownMenuItem>
                        ))}
                    </div>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * Format an ISO timestamp using the user's current browser locale.
 */
function formatNotificationTime(value: string): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'Time unavailable';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}
