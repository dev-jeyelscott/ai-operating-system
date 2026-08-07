import * as React from 'react';
import { SidebarInset } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import type { AppVariant } from '@/types';

type Props = React.ComponentProps<'main'> & {
    variant?: AppVariant;
};

/**
 * Render the application's single primary content landmark.
 *
 * The stable ID and negative tab index provide the destination for the global
 * skip link without adding the landmark to sequential keyboard navigation.
 */
export function AppContent({
    variant = 'sidebar',
    children,
    className,
    id = 'main-content',
    tabIndex = -1,
    ...props
}: Props) {
    if (variant === 'sidebar') {
        return (
            <SidebarInset
                id={id}
                tabIndex={tabIndex}
                className={className}
                {...props}
            >
                {children}
            </SidebarInset>
        );
    }

    return (
        <main
            id={id}
            tabIndex={tabIndex}
            className={cn(
                'mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-4 rounded-xl',
                className,
            )}
            {...props}
        >
            {children}
        </main>
    );
}
