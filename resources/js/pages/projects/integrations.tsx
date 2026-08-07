import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, KeyRound, Plug, ShieldCheck } from 'lucide-react';
import type { ComponentProps, ReactNode } from 'react';
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
import { ProjectConfigurationNavigation } from '@/features/projects/components/project-configuration-navigation';
import { ProjectReadinessPanel } from '@/features/projects/components/project-readiness-panel';
import type {
    ProjectCodexOverview,
    ProjectConfigurationScreenProps,
    ProjectConfigurationValidation,
} from '@/types';

type FormErrors = Record<string, string | undefined>;

/**
 * Format an ISO timestamp for the current browser locale.
 */
function formatDate(value: string | null): string {
    return value === null ? 'Never' : new Date(value).toLocaleString();
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
            <dd className="mt-1 text-sm font-medium break-words">{children}</dd>
        </div>
    );
}

/**
 * Resolve a human-readable connection label.
 */
function connectionStatus(status: 'connected' | 'failed' | null): string {
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
 * Render safe integration configuration without ever receiving provider secrets.
 */
export default function ProjectIntegrations({
    organization,
    project,
    integration,
    codex,
    validation,
    permissions,
    urls,
}: ProjectConfigurationScreenProps) {
    const canManage =
        permissions.manageIntegrations && project.archivedAt === null;
    const providerValidation = integrationValidation(validation);

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
                            Configure project-scoped provider access. Credential
                            values are write-only and are never returned to the
                            browser after submission.
                        </p>
                    </div>

                    {canManage && (
                        <Button asChild variant="outline">
                            <Link href={urls.setupSteps.integrations ?? urls.setup}>
                                <Plug aria-hidden="true" />
                                Configure Notion
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
                    validation={providerValidation}
                    setupUrl={urls.setup}
                    setupStepUrls={urls.setupSteps}
                    completeMessage="Required provider credentials and connection checks are valid."
                    incompleteMessage="Resolve the following provider requirements before project start."
                />

                <div className="grid gap-6 lg:grid-cols-2">
                    <NotionConnectionCard integration={integration} />
                    <NotionCredentialCard integration={integration} />
                </div>

                <section className="rounded-xl border bg-card shadow-sm">
                    <div className="border-b px-6 py-5">
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <h2 className="flex items-center gap-2 font-semibold">
                                    <ShieldCheck aria-hidden="true" className="size-4" />
                                    Codex provider policy
                                </h2>
                                <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                                    This configures policy only. AIOS-243 does not
                                    register a real Codex execution provider or
                                    permit repository writes.
                                </p>
                            </div>

                            <span className="rounded-full border px-2.5 py-1 text-xs font-medium">
                                {codex.policy.enabled ? 'Enabled' : 'Disabled'}
                            </span>
                        </div>
                    </div>

                    <CodexPolicyForm
                        codex={codex}
                        action={urls.codexPolicy}
                        disabled={!canManage}
                    />
                </section>

                <section className="rounded-xl border bg-card shadow-sm">
                    <div className="border-b px-6 py-5">
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <h2 className="flex items-center gap-2 font-semibold">
                                    <KeyRound aria-hidden="true" className="size-4" />
                                    Codex credential and preflight
                                </h2>
                                <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                                    A new credential is stored only after a
                                    successful server-side read-only model check.
                                    Leave both credential fields blank to retest
                                    the current stored credential.
                                </p>
                            </div>

                            <span className="rounded-full border px-2.5 py-1 text-xs font-medium">
                                {connectionStatus(codex.connection.status)}
                            </span>
                        </div>
                    </div>

                    <div className="grid gap-6 p-6 lg:grid-cols-[1fr_0.8fr]">
                        <CodexCredentialForm
                            codex={codex}
                            action={urls.codexTest}
                            disabled={!canManage}
                        />

                        <CodexSafeMetadata codex={codex} />
                    </div>
                </section>

                <section className="rounded-xl border bg-muted/20 p-5">
                    <h2 className="text-sm font-semibold">Security boundary</h2>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Opening this screen performs no provider request. Only an
                        authorized form submission can run preflight. The browser
                        never receives stored Codex credentials, ciphertext,
                        Authorization headers, or raw provider responses.
                    </p>
                </section>
            </div>
        </>
    );
}

/**
 * Render safe Notion connection metadata.
 */
function NotionConnectionCard({
    integration,
}: Pick<ProjectConfigurationScreenProps, 'integration'>) {
    return (
        <section className="rounded-xl border bg-card p-6 shadow-sm">
            <div className="flex items-center justify-between gap-4">
                <h2 className="font-semibold">Notion connection</h2>
                <span className="rounded-full border px-2.5 py-1 text-xs font-medium">
                    {connectionStatus(integration.status)}
                </span>
            </div>

            <dl className="mt-5 space-y-5">
                <DefinitionItem label="Workspace">
                    {integration.workspaceName ??
                        integration.workspaceId ??
                        'Not verified'}
                </DefinitionItem>
                <DefinitionItem label="Database">
                    {integration.databaseName ??
                        integration.databaseId ??
                        'Not verified'}
                </DefinitionItem>
                <DefinitionItem label="Last tested">
                    {formatDate(integration.lastTestedAt)}
                </DefinitionItem>
                <DefinitionItem label="Latest failure">
                    {integration.lastFailureCode
                        ? humanize(integration.lastFailureCode)
                        : 'None'}
                </DefinitionItem>
            </dl>
        </section>
    );
}

/**
 * Render safe Notion credential metadata.
 */
function NotionCredentialCard({
    integration,
}: Pick<ProjectConfigurationScreenProps, 'integration'>) {
    return (
        <section className="rounded-xl border bg-card p-6 shadow-sm">
            <h2 className="font-semibold">Notion credential metadata</h2>
            <p className="mt-2 text-sm text-muted-foreground">
                Credential values are encrypted separately and are never
                returned by this page.
            </p>

            <dl className="mt-5 space-y-5">
                <DefinitionItem label="Configured">
                    {integration.credential.configured ? 'Yes' : 'No'}
                </DefinitionItem>
                <DefinitionItem label="Version">
                    {integration.credential.version ?? 'Not configured'}
                </DefinitionItem>
                <DefinitionItem label="Created">
                    {formatDate(integration.credential.createdAt)}
                </DefinitionItem>
                <DefinitionItem label="Last rotated">
                    {formatDate(integration.credential.rotatedAt)}
                </DefinitionItem>
            </dl>
        </section>
    );
}

/**
 * Render the explicit Codex provider policy form.
 */
function CodexPolicyForm({
    codex,
    action,
    disabled,
}: {
    codex: ProjectCodexOverview;
    action: string;
    disabled: boolean;
}) {
    const policy = codex.policy;

    return (
        <Form action={action} method="put" className="grid gap-6 p-6" disableWhileProcessing>
            {({ errors, processing }) => (
                <>
                    <InputError message={errors.policy} />

                    <div className="grid gap-5 md:grid-cols-3">
                        <SelectField
                            id="codex-enabled"
                            label="Codex enabled"
                            name="enabled"
                            defaultValue={policy.enabled ? '1' : '0'}
                            options={[
                                { value: '0', label: 'Disabled' },
                                { value: '1', label: 'Enabled' },
                            ]}
                            error={errors['codex_policy.enabled']}
                        />

                        <SelectField
                            id="codex-fallback"
                            label="Use in fallback order"
                            name="fallback_enabled"
                            defaultValue={codex.fallbackEnabled ? '1' : '0'}
                            options={[
                                { value: '0', label: 'No' },
                                { value: '1', label: 'Yes' },
                            ]}
                            error={errors.fallback_enabled}
                        />

                        <TextField
                            id="codex-model"
                            label="Model identifier"
                            name="model_identifier"
                            defaultValue={policy.model_identifier}
                            required
                            spellCheck={false}
                            error={errors['codex_policy.model_identifier']}
                        />
                    </div>

                    <TextField
                        id="codex-capabilities"
                        label="Allowed capabilities"
                        name="allowed_capabilities"
                        defaultValue={policy.allowed_capabilities.join(', ')}
                        description="Canonical capabilities only: planning.generate, development.execute, quality_assurance.review."
                        required
                        spellCheck={false}
                        error={errors['codex_policy.allowed_capabilities']}
                    />

                    <div className="grid gap-5 md:grid-cols-2">
                        <SelectField
                            id="codex-reasoning-min"
                            label="Minimum reasoning"
                            name="reasoning_minimum"
                            defaultValue={policy.reasoning.minimum}
                            options={reasoningOptions}
                            error={errors['codex_policy.reasoning.minimum']}
                        />
                        <SelectField
                            id="codex-reasoning-max"
                            label="Maximum reasoning"
                            name="reasoning_maximum"
                            defaultValue={policy.reasoning.maximum}
                            options={reasoningOptions}
                            error={errors['codex_policy.reasoning.maximum']}
                        />
                    </div>

                    <input type="hidden" name="sandbox_planning" value="read-only" />
                    <input type="hidden" name="sandbox_quality_assurance" value="read-only" />
                    <input type="hidden" name="network_default" value="deny" />

                    <div className="grid gap-5 md:grid-cols-2">
                        <SelectField
                            id="codex-development-sandbox"
                            label="Development sandbox"
                            name="sandbox_development"
                            defaultValue={policy.sandbox.development}
                            options={[
                                { value: 'read-only', label: 'Read only' },
                                { value: 'workspace-write', label: 'Workspace write' },
                            ]}
                            error={errors['codex_policy.sandbox.development']}
                        />
                        <SelectField
                            id="codex-network-escalation"
                            label="Network escalation with approval"
                            name="network_allow_escalation_with_approval"
                            defaultValue={
                                policy.network.allow_escalation_with_approval
                                    ? '1'
                                    : '0'
                            }
                            options={[
                                { value: '0', label: 'Not allowed' },
                                { value: '1', label: 'Allowed with approval' },
                            ]}
                            error={
                                errors[
                                    'codex_policy.network.allow_escalation_with_approval'
                                ]
                            }
                        />
                    </div>

                    <p className="text-xs text-muted-foreground">
                        Planning and QA are fixed to read-only. Network is fixed
                        to deny-by-default. Later execution tickets may honor an
                        explicitly approved network escalation; AIOS-243 does not.
                    </p>

                    <div className="grid gap-5 md:grid-cols-3">
                        <TextField
                            id="codex-budget"
                            label="Codex budget limit (minor units)"
                            name="codex_budget_limit_minor"
                            type="number"
                            min="0"
                            step="1"
                            defaultValue={
                                policy.budget_limit_minor?.toString() ?? ''
                            }
                            placeholder="Optional"
                            error={errors['codex_policy.budget_limit_minor']}
                        />
                        <TextField
                            id="codex-timeout"
                            label="Timeout seconds"
                            name="timeout_seconds"
                            type="number"
                            min="30"
                            max="3600"
                            step="1"
                            defaultValue={policy.timeout_seconds.toString()}
                            required
                            error={errors['codex_policy.timeout_seconds']}
                        />
                        <TextField
                            id="codex-retries"
                            label="Retry limit"
                            name="retry_limit"
                            type="number"
                            min="0"
                            max="3"
                            step="1"
                            defaultValue={policy.retry_limit.toString()}
                            required
                            error={errors['codex_policy.retry_limit']}
                        />
                    </div>

                    <div className="flex justify-end border-t pt-5">
                        <Button type="submit" disabled={disabled || processing}>
                            {processing ? 'Saving policy...' : 'Save Codex policy'}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

/**
 * Render the write-only credential form and server-side preflight trigger.
 */
function CodexCredentialForm({
    codex,
    action,
    disabled,
}: {
    codex: ProjectCodexOverview;
    action: string;
    disabled: boolean;
}) {
    return (
        <Form action={action} method="post" className="grid gap-5" disableWhileProcessing resetOnSuccess={['credential', 'credential_confirmation']}>
            {({ errors, processing }) => (
                <>
                    <InputError message={errors.connection} />

                    <TextField
                        id="codex-credential"
                        label={
                            codex.credential.configured
                                ? 'New Codex credential (optional)'
                                : 'Codex credential'
                        }
                        name="credential"
                        type="password"
                        autoComplete="new-password"
                        defaultValue=""
                        required={!codex.credential.configured}
                        description="Write-only. Leave blank to retest the currently stored credential."
                        error={errors.credential}
                    />

                    <TextField
                        id="codex-credential-confirmation"
                        label="Confirm new Codex credential"
                        name="credential_confirmation"
                        type="password"
                        autoComplete="new-password"
                        defaultValue=""
                        required={!codex.credential.configured}
                        description="Required whenever a new credential is submitted."
                        error={errors.credential_confirmation}
                    />

                    <Button type="submit" disabled={disabled || processing}>
                        {processing
                            ? 'Running preflight...'
                            : codex.credential.configured
                              ? 'Retest or rotate credential'
                              : 'Validate and store credential'}
                    </Button>
                </>
            )}
        </Form>
    );
}

/**
 * Render browser-safe Codex credential and connection metadata.
 */
function CodexSafeMetadata({ codex }: { codex: ProjectCodexOverview }) {
    return (
        <div className="rounded-lg border bg-muted/20 p-5">
            <h3 className="text-sm font-semibold">Safe metadata</h3>
            <dl className="mt-4 space-y-4">
                <DefinitionItem label="Credential configured">
                    {codex.credential.configured ? 'Yes' : 'No'}
                </DefinitionItem>
                <DefinitionItem label="Credential scope">
                    Project only
                </DefinitionItem>
                <DefinitionItem label="Credential version">
                    {codex.credential.version ?? 'Not configured'}
                </DefinitionItem>
                <DefinitionItem label="Verified credential version">
                    {codex.connection.verifiedCredentialVersion ?? 'None'}
                </DefinitionItem>
                <DefinitionItem label="Last tested">
                    {formatDate(codex.connection.lastTestedAt)}
                </DefinitionItem>
                <DefinitionItem label="Last connected">
                    {formatDate(codex.connection.lastConnectedAt)}
                </DefinitionItem>
                <DefinitionItem label="Latest failure">
                    {codex.connection.failureCode
                        ? humanize(codex.connection.failureCode)
                        : 'None'}
                </DefinitionItem>
                <DefinitionItem label="Provider request ID">
                    {codex.connection.providerRequestId ?? 'None'}
                </DefinitionItem>
            </dl>
        </div>
    );
}

const reasoningOptions = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
];

/**
 * Render one text input with accessible description/error linkage.
 */
function TextField({
    id,
    label,
    name,
    description,
    error,
    ...props
}: Omit<ComponentProps<typeof Input>, 'id' | 'name'> & {
    id: string;
    label: string;
    name: string;
    description?: string;
    error?: string;
}) {
    const descriptionId = description ? `${id}-description` : undefined;
    const errorId = error ? `${id}-error` : undefined;
    const describedBy = [descriptionId, errorId].filter(Boolean).join(' ');

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                name={name}
                aria-invalid={Boolean(error)}
                aria-describedby={describedBy || undefined}
                {...props}
            />
            {description && (
                <p id={descriptionId} className="text-xs text-muted-foreground">
                    {description}
                </p>
            )}
            <InputError id={`${id}-error`} message={error} />
        </div>
    );
}

/**
 * Render one controlled select field using shadcn/ui primitives.
 */
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
                <SelectTrigger
                    id={id}
                    aria-invalid={Boolean(error)}
                    aria-describedby={error ? `${id}-error` : undefined}
                >
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
