import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { ProjectFormFields } from '@/features/projects/components/project-form-fields';
import type { OrganizationSummary, ProjectTypeOption } from '@/types';
import { index, store } from '@/routes/organizations/projects';

type Props = {
    organization: OrganizationSummary;
    projectTypes: ProjectTypeOption[];
};

/**
 * Render the organization-scoped project creation form.
 */
export default function CreateProject({ organization, projectTypes }: Props) {
    return (
        <>
            <Head title="Create project" />

            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 p-4 md:p-6">
                <header>
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

                    <h1 className="mt-4 text-2xl font-semibold tracking-tight">
                        Create project
                    </h1>

                    <p className="mt-1 text-sm text-muted-foreground">
                        Create a new draft project inside {organization.name}.
                    </p>
                </header>

                <Form
                    {...store.form({
                        organization: organization.slug,
                    })}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6 rounded-xl border bg-card p-6 shadow-sm"
                >
                    {({ processing, errors }) => (
                        <>
                            <ProjectFormFields
                                errors={errors}
                                projectTypes={projectTypes}
                            />

                            <div className="flex justify-end gap-3 border-t pt-6">
                                <Button asChild variant="outline">
                                    <Link
                                        href={index({
                                            organization: organization.slug,
                                        })}
                                    >
                                        Cancel
                                    </Link>
                                </Button>

                                <Button type="submit" disabled={processing}>
                                    {processing
                                        ? 'Creating project...'
                                        : 'Create project'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
