import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Settings2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { ProjectConfigurationNavigation } from '@/features/projects/components/project-configuration-navigation';
import { ProjectReadinessPanel } from '@/features/projects/components/project-readiness-panel';
import type { ProjectConfigurationScreenProps } from '@/types';

/**
 * Format snake_case identifiers for human-readable display.
 */
function humanize(value: string): string {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

/**
 * Format a minor-unit budget using the configured currency.
 */
function formatBudget(
    limitMinor: number | null,
    currency: string,
): string {
    if (limitMinor === null) {
        return 'Not configured';
    }

    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency,
        }).format(limitMinor / 100);
    } catch {
        return `${currency} ${(limitMinor / 100).toFixed(2)}`;
    }
}

/**
 * Render a section with a consistent heading and card treatment.
 */
function SettingsSection({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    return (
        <section className="rounded-xl border bg-card p-6 shadow-sm">
            <h2 className="font-semibold">{title}</h2>
            <dl className="mt-5 grid gap-5 md:grid-cols-2">{children}</dl>
        </section>
    );
}

/**
 * Render one label/value pair.
 */
function DefinitionItem({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div>
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="mt-1 text-sm font-medium">{children}</dd>
        </div>
    );
}

/**
 * Render a list or an explicit empty-state label.
 */
function ListValue({ values }: { values: string[] }) {
    return (
        <span>
            {values.length > 0
                ? values.map(humanize).join(', ')
                : 'Not configured'}
        </span>
    );
}

/**
 * Render a stored validation command without executing it.
 */
function CommandValue({ value }: { value: string | null }) {
    if (value === null) {
        return <span>Not configured</span>;
    }

    return (
        <code className="break-all rounded bg-muted px-2 py-1 text-xs">
            {value}
        </code>
    );
}

/**
 * Render persisted project settings and deterministic readiness results.
 */
export default function ProjectSettings({
    organization,
    project,
    configuration,
    validation,
    permissions,
    urls,
}: ProjectConfigurationScreenProps) {
    const canEdit =
        permissions.update && project.archivedAt === null;

    return (
        <>
            <Head title={`${project.name} settings`} />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <Button asChild variant="ghost" size="sm">
                            <Link href={urls.project}>
                                <ArrowLeft aria-hidden="true" />
                                Back to project
                            </Link>
                        </Button>

                        <p className="mt-4 text-sm text-muted-foreground">
                            {organization.name}
                        </p>

                        <h1 className="mt-1 text-2xl font-semibold tracking-tight">
                            Project settings
                        </h1>

                        <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                            Review the persisted configuration used by project
                            preflight and future workflow execution.
                        </p>
                    </div>

                    {canEdit && (
                        <Button asChild>
                            <Link href={urls.setup}>
                                <Settings2 aria-hidden="true" />
                                Update configuration
                            </Link>
                        </Button>
                    )}
                </header>

                <ProjectConfigurationNavigation
                    active="settings"
                    settingsUrl={urls.settings}
                    integrationsUrl={urls.integrations}
                />

                <ProjectReadinessPanel
                    title="Project readiness"
                    validation={validation}
                    setupUrl={urls.setup}
                    setupStepUrls={urls.setupSteps}
                />

                {configuration === null ? (
                    <section className="rounded-xl border bg-card p-6 shadow-sm">
                        <h2 className="font-semibold">
                            Configuration unavailable
                        </h2>
                        <p className="mt-2 text-sm text-muted-foreground">
                            This project does not currently have a persisted
                            configuration record. Follow the readiness
                            remediation above to initialize it.
                        </p>
                    </section>
                ) : (
                    <>
                        <SettingsSection title="Configuration metadata">
                            <DefinitionItem label="Schema version">
                                {configuration.schemaVersion}
                            </DefinitionItem>

                            <DefinitionItem label="Revision">
                                {configuration.revision}
                            </DefinitionItem>
                        </SettingsSection>

                        <SettingsSection title="Technology stack">
                            <DefinitionItem label="Languages">
                                <ListValue
                                    values={
                                        configuration.technologyStack.languages
                                    }
                                />
                            </DefinitionItem>

                            <DefinitionItem label="Frameworks">
                                <ListValue
                                    values={
                                        configuration.technologyStack.frameworks
                                    }
                                />
                            </DefinitionItem>

                            <DefinitionItem label="Databases">
                                <ListValue
                                    values={
                                        configuration.technologyStack.databases
                                    }
                                />
                            </DefinitionItem>

                            <DefinitionItem label="Infrastructure">
                                <ListValue
                                    values={
                                        configuration.technologyStack
                                            .infrastructure
                                    }
                                />
                            </DefinitionItem>

                            <DefinitionItem label="Package managers">
                                <ListValue
                                    values={
                                        configuration.technologyStack
                                            .package_managers
                                    }
                                />
                            </DefinitionItem>

                            <DefinitionItem label="Runtimes">
                                <ListValue
                                    values={
                                        configuration.technologyStack.runtimes
                                    }
                                />
                            </DefinitionItem>
                        </SettingsSection>

                        <SettingsSection title="Repository metadata">
                            <DefinitionItem label="Provider">
                                {configuration.repository.provider
                                    ? humanize(
                                          configuration.repository.provider,
                                      )
                                    : 'Not configured'}
                            </DefinitionItem>

                            <DefinitionItem label="Repository URL">
                                {configuration.repository.url ?? 'Not configured'}
                            </DefinitionItem>

                            <DefinitionItem label="Default branch">
                                {configuration.repository.defaultBranch ??
                                    'Not configured'}
                            </DefinitionItem>

                            <DefinitionItem label="Integration branch">
                                {configuration.repository.integrationBranch}
                            </DefinitionItem>
                        </SettingsSection>

                        <SettingsSection title="Validation commands">
                            <DefinitionItem label="Build">
                                <CommandValue
                                    value={configuration.commands.build}
                                />
                            </DefinitionItem>

                            <DefinitionItem label="Test">
                                <CommandValue
                                    value={configuration.commands.test}
                                />
                            </DefinitionItem>

                            <DefinitionItem label="Lint">
                                <CommandValue
                                    value={configuration.commands.lint}
                                />
                            </DefinitionItem>

                            <DefinitionItem label="Static analysis">
                                <CommandValue
                                    value={
                                        configuration.commands.staticAnalysis
                                    }
                                />
                            </DefinitionItem>

                            <DefinitionItem label="Security">
                                <CommandValue
                                    value={configuration.commands.security}
                                />
                            </DefinitionItem>
                        </SettingsSection>

                        <SettingsSection title="Execution policy">
                            <DefinitionItem label="Default reasoning">
                                {humanize(
                                    configuration.policy.defaultReasoning,
                                )}
                            </DefinitionItem>

                            <DefinitionItem label="Autonomy level">
                                {humanize(
                                    configuration.policy.autonomyLevel,
                                )}
                            </DefinitionItem>

                            <DefinitionItem label="Automatic retry limit">
                                {configuration.policy.automaticRetryLimit}
                            </DefinitionItem>

                            <DefinitionItem label="Budget">
                                {formatBudget(
                                    configuration.policy.budgetLimitMinor,
                                    configuration.policy.budgetCurrency,
                                )}
                            </DefinitionItem>

                            <DefinitionItem label="Allowed providers">
                                <ListValue
                                    values={
                                        configuration.policy.provider
                                            .allowed_provider_ids
                                    }
                                />
                            </DefinitionItem>

                            <DefinitionItem label="Fallback order">
                                <ListValue
                                    values={
                                        configuration.policy.provider
                                            .fallback_order
                                    }
                                />
                            </DefinitionItem>

                            <DefinitionItem label="Required approvals">
                                <ListValue
                                    values={Object.entries(
                                        configuration.policy.approval,
                                    )
                                        .filter(([, required]) => required)
                                        .map(([approval]) => approval)}
                                />
                            </DefinitionItem>

                            <DefinitionItem label="Notification channels">
                                <ListValue
                                    values={
                                        configuration.policy.notification
                                            .channels
                                    }
                                />
                            </DefinitionItem>

                            <DefinitionItem label="Notification events">
                                <ListValue
                                    values={
                                        configuration.policy.notification.events
                                    }
                                />
                            </DefinitionItem>
                        </SettingsSection>

                        <SettingsSection title="Required documents">
                            <DefinitionItem label="Document classes">
                                <ListValue
                                    values={configuration.requiredDocuments}
                                />
                            </DefinitionItem>
                        </SettingsSection>
                    </>
                )}
            </div>
        </>
    );
}