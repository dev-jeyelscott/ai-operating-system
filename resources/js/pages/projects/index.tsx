import { Head, Link } from '@inertiajs/react';
import { Archive, FolderKanban, Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { OrganizationSummary, ProjectPaginator } from '@/types';
import { create, show } from '@/routes/organizations/projects';

type Props = {
    organization: OrganizationSummary;
    projects: ProjectPaginator;
    filters: {
        archived: boolean;
    };
    filterUrls: {
        current: string;
        archived: string;
    };
    can: {
        create: boolean;
    };
};

/**
 * Render the current or archived projects for one organization.
 */
export default function ProjectIndex({
    organization,
    projects,
    filters,
    filterUrls,
    can,
}: Props) {
    return (
        <>
            <Head title={`Projects - ${organization.name}`} />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Projects
                        </h1>

                        <p className="mt-1 text-sm text-muted-foreground">
                            Manage projects owned by {organization.name}.
                        </p>
                    </div>

                    {can.create && (
                        <Button asChild>
                            <Link
                                href={create({
                                    organization: organization.slug,
                                })}
                            >
                                <Plus aria-hidden="true" />
                                Create project
                            </Link>
                        </Button>
                    )}
                </header>

                <div className="flex gap-2">
                    <Button
                        asChild
                        variant={filters.archived ? 'outline' : 'default'}
                        size="sm"
                    >
                        <Link href={filterUrls.current}>
                            <FolderKanban aria-hidden="true" />
                            Current
                        </Link>
                    </Button>

                    <Button
                        asChild
                        variant={filters.archived ? 'default' : 'outline'}
                        size="sm"
                    >
                        <Link href={filterUrls.archived}>
                            <Archive aria-hidden="true" />
                            Archived
                        </Link>
                    </Button>
                </div>

                {projects.data.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-10 text-center">
                        <FolderKanban className="mx-auto size-10 text-muted-foreground" />

                        <h2 className="mt-4 font-medium">
                            {filters.archived
                                ? 'No archived projects'
                                : 'No current projects'}
                        </h2>

                        <p className="mt-1 text-sm text-muted-foreground">
                            {filters.archived
                                ? 'Projects you archive will appear here.'
                                : 'Create the first project for this organization.'}
                        </p>
                    </div>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {projects.data.map((project) => (
                            <Link
                                key={project.id}
                                href={show({
                                    organization: organization.slug,
                                    project: project.slug,
                                })}
                                className="group rounded-xl border bg-card p-5 shadow-sm transition hover:border-primary/50 hover:shadow-md"
                            >
                                <div className="flex items-start justify-between gap-4">
                                    <div className="min-w-0">
                                        <h2 className="truncate font-semibold group-hover:text-primary">
                                            {project.name}
                                        </h2>

                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {project.projectType.label}
                                        </p>
                                    </div>

                                    <span className="shrink-0 rounded-full border px-2.5 py-1 text-xs font-medium">
                                        {project.status.label}
                                    </span>
                                </div>

                                <p className="mt-4 line-clamp-3 min-h-15 text-sm text-muted-foreground">
                                    {project.description ??
                                        'No project description provided.'}
                                </p>

                                <p className="mt-5 text-xs text-muted-foreground">
                                    Updated{' '}
                                    {project.updatedAt
                                        ? new Date(
                                              project.updatedAt,
                                          ).toLocaleString()
                                        : 'recently'}
                                </p>
                            </Link>
                        ))}
                    </div>
                )}

                {projects.last_page > 1 && (
                    <nav
                        aria-label="Project pagination"
                        className="flex items-center justify-between border-t pt-4"
                    >
                        <p className="text-sm text-muted-foreground">
                            Showing {projects.from ?? 0}–{projects.to ?? 0} of{' '}
                            {projects.total}
                        </p>

                        <div className="flex gap-2">
                            {projects.prev_page_url && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={projects.prev_page_url}>
                                        Previous
                                    </Link>
                                </Button>
                            )}

                            {projects.next_page_url && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={projects.next_page_url}>
                                        Next
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </nav>
                )}
            </div>
        </>
    );
}
