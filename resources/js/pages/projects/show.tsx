import { Form, Head, Link } from '@inertiajs/react';
import {
    Archive,
    ArrowLeft,
    Pencil,
    Plug,
    RotateCcw,
    Settings2,
    SlidersHorizontal,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    archive as archiveProject,
    edit,
    index,
    restore as restoreProject,
} from '@/routes/organizations/projects';
import type {
    OrganizationSummary,
    ProjectPermissions,
    ProjectSummary,
} from '@/types';

type Props = {
    organization: OrganizationSummary;
    project: ProjectSummary;
    permissions: ProjectPermissions;
    setupUrl: string;
    configurationUrls: {
        settings: string;
        integrations: string;
    };
};

/**
 * Render project details and policy-authorized administrative actions.
 */
export default function ShowProject({
    organization,
    project,
    permissions,
    setupUrl,
    configurationUrls,
}: Props) {
    const isArchived = project.archivedAt !== null;

    return (
        <>
            <Head title={project.name} />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <Button asChild variant="ghost" size="sm">
                            <Link
                                href={index({
                                    organization: organization.slug,
                                })}
                            >
                                <ArrowLeft aria-hidden="true" />
                                Back to projects
                            </Link>
                        </Button>

                        <div className="mt-4 flex flex-wrap items-center gap-3">
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {project.name}
                            </h1>

                            <span className="rounded-full border px-2.5 py-1 text-xs font-medium">
                                {project.status.label}
                            </span>

                            {isArchived && (
                                <span className="rounded-full bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground">
                                    Archived
                                </span>
                            )}
                        </div>

                        <p className="mt-2 text-sm text-muted-foreground">
                            {project.projectType.label} · {organization.name}
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <Link href={configurationUrls.settings}>
                                <SlidersHorizontal aria-hidden="true" />
                                Settings
                            </Link>
                        </Button>

                        <Button asChild variant="outline">
                            <Link href={configurationUrls.integrations}>
                                <Plug aria-hidden="true" />
                                Integrations
                            </Link>
                        </Button>
                        {permissions.update && !isArchived && (
                            <Button asChild>
                                <Link href={setupUrl}>
                                    <Settings2 aria-hidden="true" />
                                    Configure project
                                </Link>
                            </Button>
                        )}

                        {permissions.update && (
                            <Button asChild variant="outline">
                                <Link
                                    href={edit({
                                        organization: organization.slug,
                                        project: project.slug,
                                    })}
                                >
                                    <Pencil aria-hidden="true" />
                                    Edit
                                </Link>
                            </Button>
                        )}

                        {!isArchived && permissions.archive && (
                            <Form
                                {...archiveProject.form({
                                    organization: organization.slug,
                                    project: project.slug,
                                })}
                                onBefore={() =>
                                    window.confirm(`Archive "${project.name}"?`)
                                }
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        disabled={processing}
                                    >
                                        <Archive aria-hidden="true" />
                                        {processing
                                            ? 'Archiving...'
                                            : 'Archive'}
                                    </Button>
                                )}
                            </Form>
                        )}

                        {isArchived && permissions.restore && (
                            <Form
                                {...restoreProject.form({
                                    organization: organization.slug,
                                    project: project.slug,
                                })}
                            >
                                {({ processing }) => (
                                    <Button type="submit" disabled={processing}>
                                        <RotateCcw aria-hidden="true" />
                                        {processing
                                            ? 'Restoring...'
                                            : 'Restore'}
                                    </Button>
                                )}
                            </Form>
                        )}
                    </div>
                </header>

                <div className="grid gap-6 lg:grid-cols-[2fr_1fr]">
                    <section className="rounded-xl border bg-card p-6 shadow-sm">
                        <h2 className="font-semibold">Description</h2>

                        <p className="mt-4 text-sm leading-6 whitespace-pre-wrap text-muted-foreground">
                            {project.description ??
                                'No project description has been provided.'}
                        </p>
                    </section>

                    <aside className="rounded-xl border bg-card p-6 shadow-sm">
                        <h2 className="font-semibold">Project details</h2>

                        <dl className="mt-4 space-y-4 text-sm">
                            <div>
                                <dt className="text-muted-foreground">Type</dt>
                                <dd className="mt-1 font-medium">
                                    {project.projectType.label}
                                </dd>
                            </div>

                            <div>
                                <dt className="text-muted-foreground">
                                    Workflow status
                                </dt>
                                <dd className="mt-1 font-medium">
                                    {project.status.label}
                                </dd>
                            </div>

                            <div>
                                <dt className="text-muted-foreground">
                                    Created
                                </dt>
                                <dd className="mt-1 font-medium">
                                    {project.createdAt
                                        ? new Date(
                                              project.createdAt,
                                          ).toLocaleString()
                                        : 'Unknown'}
                                </dd>
                            </div>

                            <div>
                                <dt className="text-muted-foreground">
                                    Last updated
                                </dt>
                                <dd className="mt-1 font-medium">
                                    {project.updatedAt
                                        ? new Date(
                                              project.updatedAt,
                                          ).toLocaleString()
                                        : 'Unknown'}
                                </dd>
                            </div>

                            {project.archivedAt && (
                                <div>
                                    <dt className="text-muted-foreground">
                                        Archived
                                    </dt>
                                    <dd className="mt-1 font-medium">
                                        {new Date(
                                            project.archivedAt,
                                        ).toLocaleString()}
                                    </dd>
                                </div>
                            )}
                        </dl>
                    </aside>
                </div>
            </div>
        </>
    );
}
