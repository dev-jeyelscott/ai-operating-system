import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Plug } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { ProjectConfigurationNavigation } from '@/features/projects/components/project-configuration-navigation';
import { ProjectReadinessPanel } from '@/features/projects/components/project-readiness-panel';
import type {
    ProjectConfigurationScreenProps,
    ProjectConfigurationValidation,
} from '@/types';

/**
 * Format an ISO timestamp for the current browser locale.
 */
function formatDate(value: string | null): string {
    return value === null
        ? 'Never'
        : new Date(value).toLocaleString();
}

/**
 * Convert a machine-readable value into a display label.
 */
function humanize(value: string): string {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

/**
 * Render one integration metadata value.
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
 * Resolve a human-readable Notion connection label.
 */
function connectionStatus(
    status: 'connected' | 'failed' | null,
): string {
    if (status === 'connected') {
        return 'Connected';
    }

    if (status === 'failed') {
        return 'Connection failed';
    }

    return 'Not tested';
}

/**
 * Restrict the global completeness result to integration requirements.
 */
function integrationValidation(
    validation: ProjectConfigurationValidation,
): ProjectConfigurationValidation {
    const issues = validation.missingConfiguration.filter(
        (issue) => issue.step === 'integrations',
    );

    return {
        complete: issues.length === 0,
        missingConfiguration: issues,
    };
}

/**
 * Render safe Notion connection and credential metadata.
 */
export default function ProjectIntegrations({
    organization,
    project,
    integration,
    validation,
    permissions,
    urls,
}: ProjectConfigurationScreenProps) {
    const canManage =
        permissions.manageIntegrations && project.archivedAt === null;

    const notionValidation = integrationValidation(validation);

    return (
        <>
            <Head title={`${project.name} integrations`} />

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
                            Project integrations
                        </h1>

                        <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                            Inspect verified provider metadata and connection
                            health without exposing credentials.
                        </p>
                    </div>

                    {canManage && (
                        <Button asChild>
                            <Link
                                href={
                                    urls.setupSteps.integrations ?? urls.setup
                                }
                            >
                                <Plug aria-hidden="true" />
                                Configure or retest
                            </Link>
                        </Button>
                    )}
                </header>

                <ProjectConfigurationNavigation
                    active="integrations"
                    settingsUrl={urls.settings}
                    integrationsUrl={urls.integrations}
                />

                <ProjectReadinessPanel
                    title="Integration readiness"
                    validation={notionValidation}
                    setupUrl={urls.setup}
                    setupStepUrls={urls.setupSteps}
                    completeMessage="The required Notion credential and connection metadata are valid."
                    incompleteMessage="Resolve the following Notion integration requirements."
                />

                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="rounded-xl border bg-card p-6 shadow-sm">
                        <div className="flex items-center justify-between gap-4">
                            <h2 className="font-semibold">
                                Notion connection
                            </h2>

                            <span className="rounded-full border px-2.5 py-1 text-xs font-medium">
                                {connectionStatus(integration.status)}
                            </span>
                        </div>

                        <dl className="mt-5 space-y-5">
                            <DefinitionItem label="Provider">
                                Notion
                            </DefinitionItem>

                            <DefinitionItem label="Workspace">
                                {integration.workspaceName ??
                                    integration.workspaceId ??
                                    'Not verified'}
                            </DefinitionItem>

                            <DefinitionItem label="Workspace ID">
                                {integration.workspaceId ?? 'Not verified'}
                            </DefinitionItem>

                            <DefinitionItem label="Database">
                                {integration.databaseName ??
                                    integration.databaseId ??
                                    'Not verified'}
                            </DefinitionItem>

                            <DefinitionItem label="Database ID">
                                {integration.databaseId ?? 'Not verified'}
                            </DefinitionItem>

                            <DefinitionItem label="Last tested">
                                {formatDate(integration.lastTestedAt)}
                            </DefinitionItem>

                            <DefinitionItem label="Last connected">
                                {formatDate(integration.lastConnectedAt)}
                            </DefinitionItem>

                            <DefinitionItem label="Latest failure">
                                {integration.lastFailureCode
                                    ? humanize(
                                          integration.lastFailureCode,
                                      )
                                    : 'None'}
                            </DefinitionItem>
                        </dl>
                    </section>

                    <section className="rounded-xl border bg-card p-6 shadow-sm">
                        <h2 className="font-semibold">
                            Credential metadata
                        </h2>

                        <p className="mt-2 text-sm text-muted-foreground">
                            Credential values are encrypted separately and are
                            never returned by this page.
                        </p>

                        <dl className="mt-5 space-y-5">
                            <DefinitionItem label="Configured">
                                {integration.credential.configured
                                    ? 'Yes'
                                    : 'No'}
                            </DefinitionItem>

                            <DefinitionItem label="Version">
                                {integration.credential.version ??
                                    'Not configured'}
                            </DefinitionItem>

                            <DefinitionItem label="Created">
                                {formatDate(
                                    integration.credential.createdAt,
                                )}
                            </DefinitionItem>

                            <DefinitionItem label="Last rotated">
                                {formatDate(
                                    integration.credential.rotatedAt,
                                )}
                            </DefinitionItem>
                        </dl>
                    </section>
                </div>

                <section className="rounded-xl border bg-muted/20 p-5">
                    <h2 className="text-sm font-semibold">
                        Read-only connection state
                    </h2>

                    <p className="mt-2 text-sm text-muted-foreground">
                        Opening this screen does not call Notion or rerun the
                        connection test. Use the authorized configuration flow
                        to validate the current credential and database access.
                    </p>
                </section>
            </div>
        </>
    );
}