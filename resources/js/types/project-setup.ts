/**
 * One ordered project setup wizard step.
 */
export type ProjectSetupStep = {
    value: string;
    label: string;
    description: string;
    completed: boolean;
    active: boolean;
    canVisit: boolean;
    href: string;
};

/**
 * Persisted technology stack.
 */
export type TechnologyStack = {
    languages: string[];
    frameworks: string[];
    databases: string[];
    infrastructure: string[];
    package_managers: string[];
    runtimes: string[];
};

/**
 * Security-sensitive Codex policy returned without any credential material.
 */
export type CodexProviderPolicy = {
    enabled: boolean;
    model_identifier: string;
    allowed_capabilities: string[];
    reasoning: {
        minimum: 'low' | 'medium' | 'high';
        maximum: 'low' | 'medium' | 'high';
    };
    sandbox: {
        planning: 'read-only';
        development: 'read-only' | 'workspace-write';
        quality_assurance: 'read-only';
    };
    network: {
        default: 'deny';
        allow_escalation_with_approval: boolean;
    };
    budget_limit_minor: number | null;
    timeout_seconds: number;
    retry_limit: number;
};

/**
 * Non-secret project setup configuration returned by Laravel.
 */
export type ProjectSetupConfiguration = {
    revision: number;
    technologyStack: TechnologyStack;
    repository: {
        provider: string | null;
        url: string | null;
        defaultBranch: string | null;
        integrationBranch: string;
    };
    commands: {
        build: string | null;
        test: string | null;
        lint: string | null;
        staticAnalysis: string | null;
        security: string | null;
    };
    requiredDocuments: string[];
    policy: {
        defaultReasoning: string;
        provider: {
            allowed_provider_ids: string[];
            fallback_order: string[];
            codex: CodexProviderPolicy;
        };
        budgetLimitMinor: number | null;
        budgetCurrency: string;
        automaticRetryLimit: number;
        autonomyLevel: string;
        approval: {
            roadmap_required: boolean;
            ticket_execution_required: boolean;
            merge_required: boolean;
        };
        notification: {
            channels: string[];
            events: string[];
        };
    };
};

export type ProjectSetupProgress = {
    currentStep: string;
    completedSteps: string[];
    completedAt: string | null;
};

/**
 * Safe project-scoped Notion connection metadata.
 */
export type ProjectIntegrationConnection = {
    provider: 'notion';
    credentialConfigured: boolean;
    status: 'connected' | 'failed' | null;
    workspaceId: string | null;
    workspaceName: string | null;
    databaseId: string | null;
    databaseName: string | null;
    lastFailureCode: string | null;
    lastTestedAt: string | null;
    lastConnectedAt: string | null;
};

export type NotionDataSourceCandidate = {
    id: string;
    name: string;
};
