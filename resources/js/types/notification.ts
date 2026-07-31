/**
 * One sanitized recipient-scoped notification displayed in the application.
 */
export type InAppNotification = {
    id: string;
    eventId: string;
    eventName: string;
    projectId: number | null;
    title: string;
    message: string;

    /**
     * Server-owned POST endpoint that marks the notification read and safely
     * redirects to its resolved application context.
     */
    actionUrl: string;

    deliveredAt: string;
    readAt: string | null;
    occurredAt: string;
};

/**
 * Shared notification payload returned by the Inertia middleware.
 */
export type NotificationInbox = {
    unreadCount: number;
    items: InAppNotification[];
};
