import type { Auth } from '@/types/auth';
import type { NotificationInbox } from '@/types/notification';
import type { OrganizationContext } from '@/types/organization';

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            organizationContext: OrganizationContext;
            notifications: NotificationInbox;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
