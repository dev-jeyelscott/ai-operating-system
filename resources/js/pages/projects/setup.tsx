import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, CheckCircle2 } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ProjectSetupStepper } from '@/features/projects/components/project-setup-stepper';
import { ValidationCommandFields } from '@/features/projects/components/validation-command-fields';
import type { ValidationCommandFormData } from '@/features/projects/components/validation-command-fields';
import type {
    OrganizationSummary,
    ProjectSetupConfiguration,
    ProjectSetupProgress,
    ProjectSetupStep,
} from '@/types';

type Props = {
    organization: OrganizationSummary;
    project: {
        id: number;
        name: string;
        slug: string;
    };
    activeStep: string;
    steps: ProjectSetupStep[];
    configuration: ProjectSetupConfiguration;
    progress: ProjectSetupProgress;
    urls: {
        update: string;
        project: string;
    };
};

type FormErrors = Record<string, string | undefined>;

const asCommaSeparated = (values: string[] | undefined): string =>
    values?.join(', ') ?? '';

/**
 * Render the active server-authoritative project setup step.
 */
export default function ProjectSetup({
    organization,
    project,
    activeStep,
    steps,
    configuration,
    progress,
    urls,
}: Props) {
    const activeStepData = steps.find((step) => step.value === activeStep);

    return (
        <>
            <Head title={`Configure ${project.name}`} />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header>
                    <Button asChild variant="ghost" size="sm">
                        <Link href={urls.project}>
                            <ArrowLeft aria-hidden="true" />
                            Back to project
                        </Link>
                    </Button>

                    <div className="mt-4">
                        <p className="text-sm text-muted-foreground">
                            {organization.name}
                        </p>

                        <h1 className="mt-1 text-2xl font-semibold tracking-tight">
                            Configure {project.name}
                        </h1>

                        <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                            Progress is saved after each valid step. Laravel
                            validates and persists every configuration change.
                        </p>
                    </div>
                </header>

                <ProjectSetupStepper steps={steps} activeStep={activeStep} />

                <section className="rounded-xl border bg-card shadow-sm">
                    <div className="border-b px-6 py-5">
                        <h2 className="text-lg font-semibold">
                            {activeStepData?.label}
                        </h2>

                        <p className="mt-1 text-sm text-muted-foreground">
                            {activeStepData?.description}
                        </p>
                    </div>

                    <Form
                        action={urls.update}
                        method="put"
                        className="p-6"
                        disableWhileProcessing
                    >
                        {({ errors, processing }) => (
                            <>
                                <InputError
                                    id="setup-step-error"
                                    message={errors.step}
                                    className="mb-5"
                                />

                                {activeStep === 'details' && (
                                    <TechnologyStackFields
                                        configuration={configuration}
                                        errors={errors}
                                    />
                                )}

                                {activeStep === 'repository' && (
                                    <RepositoryFields
                                        configuration={configuration}
                                        errors={errors}
                                    />
                                )}

                                {activeStep === 'commands' && (
                                    <CommandStep
                                        configuration={configuration}
                                        errors={errors}
                                        processing={processing}
                                    />
                                )}

                                {activeStep === 'policies' && (
                                    <PolicyFields
                                        configuration={configuration}
                                        errors={errors}
                                    />
                                )}

                                {activeStep === 'review' && (
                                    <ReviewStep
                                        configuration={configuration}
                                        progress={progress}
                                        errors={errors}
                                    />
                                )}

                                <div className="mt-8 flex flex-wrap items-center justify-between gap-3 border-t pt-5">
                                    <p className="text-xs text-muted-foreground">
                                        Configuration revision{' '}
                                        {configuration.revision}
                                    </p>

                                    <Button type="submit" disabled={processing}>
                                        {processing
                                            ? 'Saving...'
                                            : activeStep === 'review'
                                              ? 'Complete setup'
                                              : 'Save and continue'}

                                        {activeStep === 'review' ? (
                                            <CheckCircle2 aria-hidden="true" />
                                        ) : (
                                            <ArrowRight aria-hidden="true" />
                                        )}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </section>
            </div>
        </>
    );
}

function TechnologyStackFields({
    configuration,
    errors,
}: {
    configuration: ProjectSetupConfiguration;
    errors: FormErrors;
}) {
    const stack = configuration.technologyStack;

    return (
        <div className="grid gap-5 md:grid-cols-2">
            <TextField
                id="languages"
                label="Languages"
                name="languages"
                defaultValue={asCommaSeparated(stack.languages)}
                placeholder="PHP, TypeScript"
                required
                error={errors['technology_stack.languages']}
            />

            <TextField
                id="frameworks"
                label="Frameworks"
                name="frameworks"
                defaultValue={asCommaSeparated(stack.frameworks)}
                placeholder="Laravel 13, React, Inertia.js 3"
                error={errors['technology_stack.frameworks']}
            />

            <TextField
                id="databases"
                label="Databases"
                name="databases"
                defaultValue={asCommaSeparated(stack.databases)}
                placeholder="PostgreSQL, Redis"
                error={errors['technology_stack.databases']}
            />

            <TextField
                id="infrastructure"
                label="Infrastructure"
                name="infrastructure"
                defaultValue={asCommaSeparated(stack.infrastructure)}
                placeholder="Docker Compose, GitHub Actions, S3"
                error={errors['technology_stack.infrastructure']}
            />

            <TextField
                id="package-managers"
                label="Package managers"
                name="package_managers"
                defaultValue={asCommaSeparated(stack.package_managers)}
                placeholder="Composer, pnpm"
                error={errors['technology_stack.package_managers']}
            />

            <TextField
                id="runtimes"
                label="Runtimes"
                name="runtimes"
                defaultValue={asCommaSeparated(stack.runtimes)}
                placeholder="PHP 8.5, Node.js 22"
                error={errors['technology_stack.runtimes']}
            />
        </div>
    );
}

function RepositoryFields({
    configuration,
    errors,
}: {
    configuration: ProjectSetupConfiguration;
    errors: FormErrors;
}) {
    return (
        <div className="grid gap-5">
            <div className="grid gap-2">
                <Label htmlFor="repository-provider">Repository provider</Label>

                <Select
                    name="repository_provider"
                    defaultValue={configuration.repository.provider ?? 'github'}
                    required
                >
                    <SelectTrigger id="repository-provider">
                        <SelectValue placeholder="Select provider" />
                    </SelectTrigger>

                    <SelectContent>
                        <SelectItem value="github">GitHub</SelectItem>
                    </SelectContent>
                </Select>

                <InputError
                    id="repository-provider-error"
                    message={errors.repository_provider}
                />
            </div>

            <TextField
                id="repository-url"
                label="Repository URL"
                name="repository_url"
                type="url"
                defaultValue={configuration.repository.url ?? ''}
                placeholder="https://github.com/organization/repository"
                required
                error={errors.repository_url}
            />

            <div className="grid gap-5 md:grid-cols-2">
                <TextField
                    id="default-branch"
                    label="Default branch"
                    name="default_branch"
                    defaultValue={
                        configuration.repository.defaultBranch ?? 'main'
                    }
                    placeholder="main"
                    required
                    error={errors.default_branch}
                />

                <TextField
                    id="integration-branch"
                    label="Integration branch"
                    name="integration_branch"
                    defaultValue={configuration.repository.integrationBranch}
                    placeholder="develop"
                    required
                    error={errors.integration_branch}
                />
            </div>

            <p className="text-sm text-muted-foreground">
                This step stores metadata only. It does not clone, write to,
                push to, or otherwise modify the repository.
            </p>
        </div>
    );
}

function CommandStep({
    configuration,
    errors,
    processing,
}: {
    configuration: ProjectSetupConfiguration;
    errors: FormErrors;
    processing: boolean;
}) {
    const [data, setData] = useState<ValidationCommandFormData>(() => ({
        build_command: configuration.commands.build ?? '',
        test_command: configuration.commands.test ?? '',
        lint_command: configuration.commands.lint ?? '',
        static_analysis_command: configuration.commands.staticAnalysis ?? '',
        security_command: configuration.commands.security ?? '',
    }));

    return (
        <ValidationCommandFields
            data={data}
            errors={{
                build_command: errors.build_command,
                test_command: errors.test_command,
                lint_command: errors.lint_command,
                static_analysis_command: errors.static_analysis_command,
                security_command: errors.security_command,
            }}
            disabled={processing}
            onChange={(field, value) => {
                setData((current) => ({
                    ...current,
                    [field]: value,
                }));
            }}
        />
    );
}

function PolicyFields({
    configuration,
    errors,
}: {
    configuration: ProjectSetupConfiguration;
    errors: FormErrors;
}) {
    const policy = configuration.policy;

    return (
        <div className="grid gap-6">
            <div className="grid gap-5 md:grid-cols-2">
                <SelectField
                    id="default-reasoning"
                    label="Default reasoning"
                    name="default_reasoning"
                    defaultValue={policy.defaultReasoning}
                    options={[
                        { value: 'low', label: 'Low' },
                        { value: 'medium', label: 'Medium' },
                        { value: 'high', label: 'High' },
                    ]}
                    error={errors.default_reasoning}
                />

                <SelectField
                    id="autonomy-level"
                    label="Autonomy level"
                    name="autonomy_level"
                    defaultValue={policy.autonomyLevel}
                    options={[
                        { value: 'advisory', label: 'Advisory' },
                        {
                            value: 'approval_required',
                            label: 'Approval required',
                        },
                        {
                            value: 'policy_controlled',
                            label: 'Policy controlled',
                        },
                    ]}
                    error={errors.autonomy_level}
                />
            </div>

            <div className="grid gap-5 md:grid-cols-2">
                <TextField
                    id="allowed-provider-ids"
                    label="Allowed provider IDs"
                    name="allowed_provider_ids"
                    defaultValue={asCommaSeparated(
                        policy.provider.allowed_provider_ids,
                    )}
                    placeholder="simulation"
                    required
                    error={errors['provider_policy.allowed_provider_ids']}
                />

                <TextField
                    id="fallback-order"
                    label="Provider fallback order"
                    name="fallback_order"
                    defaultValue={asCommaSeparated(
                        policy.provider.fallback_order,
                    )}
                    placeholder="simulation"
                    error={errors['provider_policy.fallback_order']}
                />
            </div>

            <div className="grid gap-5 md:grid-cols-3">
                <TextField
                    id="budget-limit"
                    label="Budget limit in minor units"
                    name="budget_limit_minor"
                    type="number"
                    min="0"
                    defaultValue={policy.budgetLimitMinor?.toString() ?? ''}
                    placeholder="10000"
                    error={errors.budget_limit_minor}
                />

                <TextField
                    id="budget-currency"
                    label="Currency"
                    name="budget_currency"
                    defaultValue={policy.budgetCurrency}
                    placeholder="USD"
                    required
                    error={errors.budget_currency}
                />

                <TextField
                    id="automatic-retry-limit"
                    label="Automatic retry limit"
                    name="automatic_retry_limit"
                    type="number"
                    min="0"
                    max="10"
                    defaultValue={policy.automaticRetryLimit.toString()}
                    required
                    error={errors.automatic_retry_limit}
                />
            </div>

            <div className="grid gap-5 md:grid-cols-3">
                <BooleanSelect
                    id="roadmap-approval"
                    label="Roadmap approval required"
                    name="roadmap_required"
                    defaultValue={policy.approval.roadmap_required}
                    error={errors['approval_policy.roadmap_required']}
                />

                <BooleanSelect
                    id="ticket-approval"
                    label="Ticket execution approval required"
                    name="ticket_execution_required"
                    defaultValue={policy.approval.ticket_execution_required}
                    error={errors['approval_policy.ticket_execution_required']}
                />

                <BooleanSelect
                    id="merge-approval"
                    label="Merge approval required"
                    name="merge_required"
                    defaultValue={policy.approval.merge_required}
                    error={errors['approval_policy.merge_required']}
                />
            </div>

            <TextField
                id="notification-events"
                label="In-app notification events"
                name="notification_events"
                defaultValue={asCommaSeparated(policy.notification.events)}
                placeholder="roadmap.ready, approval.requested, execution.failed"
                error={errors['notification_policy.events']}
            />
        </div>
    );
}

function ReviewStep({
    configuration,
    progress,
    errors,
}: {
    configuration: ProjectSetupConfiguration;
    progress: ProjectSetupProgress;
    errors: FormErrors;
}) {
    return (
        <div className="space-y-6">
            <div className="rounded-lg border bg-muted/30 p-5">
                <h3 className="font-medium">Configuration summary</h3>

                <dl className="mt-4 grid gap-4 text-sm md:grid-cols-2">
                    <SummaryItem
                        label="Languages"
                        value={asCommaSeparated(
                            configuration.technologyStack.languages,
                        )}
                    />
                    <SummaryItem
                        label="Frameworks"
                        value={asCommaSeparated(
                            configuration.technologyStack.frameworks,
                        )}
                    />
                    <SummaryItem
                        label="Repository"
                        value={configuration.repository.url ?? 'Not configured'}
                    />
                    <SummaryItem
                        label="Integration branch"
                        value={configuration.repository.integrationBranch}
                    />
                    <SummaryItem
                        label="Default reasoning"
                        value={configuration.policy.defaultReasoning}
                    />
                    <SummaryItem
                        label="Autonomy"
                        value={configuration.policy.autonomyLevel}
                    />
                    <SummaryItem
                        label="Retry limit"
                        value={String(configuration.policy.automaticRetryLimit)}
                    />
                    <SummaryItem
                        label="Completed sections"
                        value={`${progress.completedSteps.length} of 5`}
                    />
                </dl>
            </div>

            <div className="flex items-start gap-3">
                <input
                    id="setup-confirmation"
                    name="confirmation"
                    type="checkbox"
                    value="1"
                    required
                    className="mt-1 size-4 rounded border-input"
                    aria-invalid={Boolean(errors.confirmation)}
                    aria-describedby={
                        errors.confirmation
                            ? 'setup-confirmation-error'
                            : undefined
                    }
                />

                <Label htmlFor="setup-confirmation" className="leading-6">
                    I confirm that the persisted project configuration is ready
                    for the next setup phase.
                </Label>
            </div>

            <InputError
                id="setup-confirmation-error"
                message={errors.confirmation}
            />
        </div>
    );
}

function TextField({
    id,
    label,
    name,
    defaultValue,
    error,
    ...props
}: React.ComponentProps<typeof Input> & {
    label: string;
    error?: string;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>

            <Input
                id={id}
                name={name}
                defaultValue={defaultValue}
                aria-invalid={Boolean(error)}
                aria-describedby={error ? `${id}-error` : undefined}
                {...props}
            />

            <InputError id={`${id}-error`} message={error} />

            <p className="text-xs text-muted-foreground">
                Separate multiple values with commas.
            </p>
        </div>
    );
}

function SelectField({
    id,
    label,
    name,
    defaultValue,
    options,
    error,
}: {
    id: string;
    label: string;
    name: string;
    defaultValue: string;
    options: Array<{ value: string; label: string }>;
    error?: string;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>

            <Select name={name} defaultValue={defaultValue} required>
                <SelectTrigger id={id} aria-invalid={Boolean(error)}>
                    <SelectValue />
                </SelectTrigger>

                <SelectContent>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            <InputError id={`${id}-error`} message={error} />
        </div>
    );
}

function BooleanSelect({
    id,
    label,
    name,
    defaultValue,
    error,
}: {
    id: string;
    label: string;
    name: string;
    defaultValue: boolean;
    error?: string;
}) {
    return (
        <SelectField
            id={id}
            label={label}
            name={name}
            defaultValue={defaultValue ? '1' : '0'}
            options={[
                { value: '1', label: 'Required' },
                { value: '0', label: 'Not required' },
            ]}
            error={error}
        />
    );
}

function SummaryItem({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="mt-1 font-medium break-words">{value || 'None'}</dd>
        </div>
    );
}
