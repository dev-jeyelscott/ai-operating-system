import { Link, usePage } from '@inertiajs/react';
import { BookOpen, FolderGit2, FolderKanban, LayoutGrid } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { OrganizationSwitcher } from '@/features/organizations/components/organization-switcher';
import type { NavItem } from '@/types';
import { dashboard } from '@/routes';
import { dashboard as organizationDashboard } from '@/routes/organizations';
import { index as projectsIndex } from '@/routes/organizations/projects';

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
    },
];

/**
 * Renders the application sidebar with navigation scoped to the
 * currently selected organization.
 */
export function AppSidebar() {
    const { organizationContext } = usePage().props;

    const dashboardHref = organizationContext.current
        ? organizationDashboard({
              organization: organizationContext.current.slug,
          })
        : dashboard();

    const mainNavItems: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboardHref,
            icon: LayoutGrid,
        },
        ...(organizationContext.current
            ? [
                  {
                      title: 'Projects',
                      href: projectsIndex({
                          organization: organizationContext.current.slug,
                      }),
                      icon: FolderKanban,
                  },
              ]
            : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboardHref} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>

                <OrganizationSwitcher variant="sidebar" />
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
