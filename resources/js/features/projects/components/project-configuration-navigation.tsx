import { Link } from '@inertiajs/react';
import { Plug, Settings2 } from 'lucide-react';
import { cn } from '@/lib/utils';

type Props = {
    active: 'settings' | 'integrations';
    settingsUrl: string;
    integrationsUrl: string;
};

/**
 * Render accessible navigation between project configuration screens.
 */
export function ProjectConfigurationNavigation({
    active,
    settingsUrl,
    integrationsUrl,
}: Props) {
    const items = [
        {
            value: 'settings' as const,
            label: 'Settings',
            href: settingsUrl,
            icon: Settings2,
        },
        {
            value: 'integrations' as const,
            label: 'Integrations',
            href: integrationsUrl,
            icon: Plug,
        },
    ];

    return (
        <nav
            aria-label="Project configuration"
            className="flex flex-wrap gap-2 border-b"
        >
            {items.map((item) => {
                const Icon = item.icon;
                const isActive = active === item.value;

                return (
                    <Link
                        key={item.value}
                        href={item.href}
                        aria-current={isActive ? 'page' : undefined}
                        className={cn(
                            'flex items-center gap-2 border-b-2 px-4 py-3 text-sm font-medium transition-colors',
                            isActive
                                ? 'border-foreground text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )}
                    >
                        <Icon className="size-4" aria-hidden="true" />
                        {item.label}
                    </Link>
                );
            })}
        </nav>
    );
}
