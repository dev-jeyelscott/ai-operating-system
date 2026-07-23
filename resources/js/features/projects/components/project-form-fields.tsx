import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { ProjectTypeOption } from '@/types';

type ProjectFormErrors = Record<string, string | undefined>;

type ProjectFormInitialValues = {
    name?: string;
    description?: string | null;
    projectType?: string;
};

type Props = {
    errors: ProjectFormErrors;
    projectTypes: ProjectTypeOption[];
    initialValues?: ProjectFormInitialValues;
};

/**
 * Render fields shared by project creation and editing forms.
 */
export function ProjectFormFields({
    errors,
    projectTypes,
    initialValues = {},
}: Props) {
    const defaultProjectType =
        initialValues.projectType ?? projectTypes[0]?.value;

    return (
        <div className="space-y-5">
            <div className="grid gap-2">
                <Label htmlFor="project-name">Project name</Label>

                <Input
                    id="project-name"
                    name="name"
                    type="text"
                    required
                    minLength={2}
                    maxLength={120}
                    defaultValue={initialValues.name}
                    autoComplete="off"
                    autoFocus
                    placeholder="AI Operating System"
                    aria-invalid={Boolean(errors.name)}
                    aria-describedby={
                        errors.name ? 'project-name-error' : undefined
                    }
                />

                <InputError id="project-name-error" message={errors.name} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="project-type">Project type</Label>

                <Select
                    name="project_type"
                    required
                    defaultValue={defaultProjectType}
                >
                    <SelectTrigger
                        id="project-type"
                        className="w-full"
                        aria-invalid={Boolean(errors.project_type)}
                        aria-describedby={
                            errors.project_type
                                ? 'project-type-error'
                                : undefined
                        }
                    >
                        <SelectValue placeholder="Select a project type" />
                    </SelectTrigger>

                    <SelectContent>
                        {projectTypes.map((projectType) => (
                            <SelectItem
                                key={projectType.value}
                                value={projectType.value}
                            >
                                {projectType.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <InputError
                    id="project-type-error"
                    message={errors.project_type}
                />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="project-description">Description</Label>

                <textarea
                    id="project-description"
                    name="description"
                    rows={6}
                    maxLength={5000}
                    defaultValue={initialValues.description ?? ''}
                    placeholder="Describe the project, its purpose, and expected outcome."
                    aria-invalid={Boolean(errors.description)}
                    aria-describedby={
                        errors.description
                            ? 'project-description-error'
                            : 'project-description-help'
                    }
                    className="min-h-32 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:bg-input/30"
                />

                <p
                    id="project-description-help"
                    className="text-xs text-muted-foreground"
                >
                    Maximum 5,000 characters.
                </p>

                <InputError
                    id="project-description-error"
                    message={errors.description}
                />
            </div>
        </div>
    );
}
