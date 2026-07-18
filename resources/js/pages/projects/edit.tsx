import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { ProjectFormFields } from '@/features/projects/components/project-form-fields';
import type {
    OrganizationSummary,
    ProjectSummary,
    ProjectTypeOption,
} from '@/types';
import { show, update } from '@/routes/organizations/projects';

type Props = {
    organization: OrganizationSummary;
    project: ProjectSummary;
    projectTypes: ProjectTypeOption[];
};

/**
 * Render the project metadata editing form.
 */
export default function EditProject({
    organization,
    project,
    projectTypes,
}: Props) {
    return (
        <>
            <Head title={`Edit ${project.name}`} />

            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 p-4 md:p-6">
                <header>
                    <Button asChild variant="ghost" size="sm">
                        <Link
                            href={show({
                                organization: organization.slug,
                                project: project.slug,
                            })}
                        >
                            <ArrowLeft aria-hidden="true" />
                            Back to project
                        </Link>
                    </Button>

                    <h1 className="mt-4 text-2xl font-semibold tracking-tight">
                        Edit project
                    </h1>

                    <p className="mt-1 text-sm text-muted-foreground">
                        Update project metadata. The project URL and workflow
                        status will remain unchanged.
                    </p>
                </header>

                <Form
                    {...update.form({
                        organization: organization.slug,
                        project: project.slug,
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
                                initialValues={{
                                    name: project.name,
                                    description: project.description,
                                    projectType: project.projectType.value,
                                }}
                            />

                            <div className="flex justify-end gap-3 border-t pt-6">
                                <Button asChild variant="outline">
                                    <Link
                                        href={show({
                                            organization: organization.slug,
                                            project: project.slug,
                                        })}
                                    >
                                        Cancel
                                    </Link>
                                </Button>

                                <Button type="submit" disabled={processing}>
                                    {processing
                                        ? 'Saving changes...'
                                        : 'Save changes'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
