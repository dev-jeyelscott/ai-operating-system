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
    actionUrl: string | null;
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
