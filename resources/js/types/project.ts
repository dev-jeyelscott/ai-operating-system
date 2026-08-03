/**
 * A selectable project category returned by Laravel.
 */
export type ProjectTypeOption = {
    value: string;
    label: string;
};

/**
 * Explicit status or type label/value pair.
 */
export type ProjectValueLabel = {
    value: string;
    label: string;
};

/**
 * Project data serialized by ProjectController.
 */
export type ProjectSummary = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    projectType: ProjectValueLabel;
    status: ProjectValueLabel;
    archivedAt: string | null;
    createdAt: string | null;
    updatedAt: string | null;
};

/**
 * Laravel length-aware paginator payload used by the project index.
 */
export type ProjectPaginator = {
    current_page: number;
    data: ProjectSummary[];
    from: number | null;
    last_page: number;
    next_page_url: string | null;
    per_page: number;
    prev_page_url: string | null;
    to: number | null;
    total: number;
};

/**
 * Project operations exposed by the server-authoritative policy response.
 */
export type ProjectPermissions = {
    update: boolean;
    start: boolean;
    archive: boolean;
    restore: boolean;
};
