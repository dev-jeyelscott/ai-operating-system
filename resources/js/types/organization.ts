/**
 * Minimal organization data used by scoped navigation.
 */
export type OrganizationSummary = {
    id: number;
    name: string;
    slug: string;
};

/**
 * Server-authoritative organization context shared through Inertia.
 */
export type OrganizationContext = {
    current: OrganizationSummary | null;
    available: OrganizationSummary[];
};
