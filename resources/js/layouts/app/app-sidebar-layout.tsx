import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { SkipLink } from '@/components/skip-link';
import type { AppLayoutProps } from '@/types';

/**
 * Render the authenticated sidebar layout with one bypass link and one primary
 * content landmark.
 */
export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <SkipLink />

            <AppSidebar />

            <AppContent
                variant="sidebar"
                className="overflow-x-hidden focus:outline-none"
            >
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {children}
            </AppContent>
        </AppShell>
    );
}
