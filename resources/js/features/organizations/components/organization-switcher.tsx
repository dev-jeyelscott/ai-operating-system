import { router, usePage } from '@inertiajs/react';
import { Building2, Check, ChevronsUpDown, LoaderCircle } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { update } from '@/routes/organizations/current';
import type { OrganizationSummary } from '@/types';

type Props = {
    variant?: 'header' | 'sidebar';
};

/**
 * Displays the current organization and submits organization changes through
 * the server-authorized switch endpoint.
 */
export function OrganizationSwitcher({ variant = 'sidebar' }: Props) {
    const { organizationContext } = usePage().props;

    const [switchingOrganizationId, setSwitchingOrganizationId] = useState<
        number | null
    >(null);

    /**
     * Request a server-authorized organization-context change.
     */
    function switchOrganization(organization: OrganizationSummary): void {
        if (
            organization.id === organizationContext.current?.id ||
            switchingOrganizationId !== null
        ) {
            return;
        }

        setSwitchingOrganizationId(organization.id);

        router.visit(
            update({
                organization: organization.slug,
            }),
            {
                preserveScroll: true,
                onFinish: () => {
                    setSwitchingOrganizationId(null);
                },
            },
        );
    }

    const currentOrganization = organizationContext.current;

    const triggerClassName =
        variant === 'sidebar'
            ? 'w-full justify-between px-2'
            : 'max-w-64 justify-between';

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    className={triggerClassName}
                    disabled={currentOrganization === null}
                    aria-label="Switch organization"
                >
                    <span className="flex min-w-0 items-center gap-2">
                        <Building2 className="size-4 shrink-0" />

                        <span className="truncate">
                            {currentOrganization?.name ?? 'No organization'}
                        </span>
                    </span>

                    <ChevronsUpDown className="size-4 shrink-0 opacity-60" />
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent
                align={variant === 'header' ? 'end' : 'start'}
                className="w-72"
            >
                <DropdownMenuLabel>Organizations</DropdownMenuLabel>

                <DropdownMenuSeparator />

                {organizationContext.available.map((organization) => {
                    const isCurrent =
                        organization.id === currentOrganization?.id;

                    const isSwitching =
                        organization.id === switchingOrganizationId;

                    return (
                        <DropdownMenuItem
                            key={organization.id}
                            disabled={
                                isCurrent || switchingOrganizationId !== null
                            }
                            onSelect={() => {
                                switchOrganization(organization);
                            }}
                        >
                            <Building2 />

                            <span className="truncate">
                                {organization.name}
                            </span>

                            {isSwitching ? (
                                <LoaderCircle className="ml-auto animate-spin" />
                            ) : isCurrent ? (
                                <Check className="ml-auto" />
                            ) : null}
                        </DropdownMenuItem>
                    );
                })}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
