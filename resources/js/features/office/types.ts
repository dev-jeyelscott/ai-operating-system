/**
 * Stable room identifiers emitted by BuildOfficeProjection.
 */
export type OfficeRoomKey =
    | 'lobby'
    | 'planning_room'
    | 'development_floor'
    | 'qa_laboratory'
    | 'approval_room'
    | 'operations_area'
    | 'archive';

/**
 * Authoritative office-state vocabulary.
 */
export type OfficeState =
    | 'idle'
    | 'queued'
    | 'working'
    | 'reading_documents'
    | 'planning'
    | 'waiting_for_approval'
    | 'selecting_ticket'
    | 'implementing'
    | 'validating'
    | 'creating_pull_request'
    | 'reviewing'
    | 'blocked'
    | 'retrying'
    | 'waiting_for_human'
    | 'completed'
    | 'cancelled'
    | 'failed';

/**
 * One room summary projected from durable workflow state.
 */
export type OfficeRoom = {
    key: OfficeRoomKey;
    label: string;
    state: OfficeState;
    activeAgents: number;
    agentIds: string[];
    actionableCount: number;
    completedItems: number;
};

/**
 * One privacy-safe durable provider activity entry.
 */
export type OfficeActivity = {
    sequence: number;
    eventId: string;
    executionId: string | null;
    provider: string | null;
    state: OfficeState;
    summary: string;
    occurredAt: string;
};

/**
 * One logical agent projected from workflow and provider state.
 */
export type OfficeAgent = {
    id: string;
    role: string;
    layer: string;
    room: OfficeRoomKey;
    capability: string;
    workflowState: string;
    officeState: OfficeState;
    currentAction: string;
    active: boolean;
    provider: string | null;
    model?: string | null;
    providerState?: string | null;
    providerPhase?: string | null;
    providerSequence?: number;
    lastProviderMessageAt?: string | null;
    requestedReasoning: string;
    effectiveReasoning: string | null;
    ticketId: string | null;
    attemptCount: number;
    retryLimit: number;
    nextAttemptAt: string | null;
    startedAt: string | null;
    finishedAt: string | null;
    elapsedSeconds?: number | null;
    estimatedCost?: string | null;
    actualCost?: string | null;
    costCurrency?: string | null;
    confidence?: string | null;
    actualState?: string | null;
    approvalRequired?: boolean;
    approvalSummary?: string | null;
    approvalUrl?: string | null;
    recoveryRequired?: boolean;
    diagnosticCode?: string | null;
    diagnosticMessage?: string | null;
    contextUrl: string;
};

/**
 * One actionable or informational office indicator.
 */
export type OfficeIndicator = {
    key: string;
    label: string;
    count: number;
    severity: 'none' | 'info' | 'warning' | 'critical';
    actionable: boolean;
    contextUrl: string | null;
};

/**
 * Persisted office projection contract returned by Laravel.
 */
export type OfficeProjection = {
    metadata: {
        schemaVersion: number;
        fingerprint: string;
        lastEventSequence: number;
        lastEventId: string | null;
        projectedAt: string;
        rebuiltAt: string | null;
    };
    project: {
        id: number;
        name: string;
        slug: string;
        status: string;
        archived: boolean;
    };
    workflow: {
        id: string;
        state: string;
        transitionSequence: number;
        completedAt: string | null;
    } | null;
    roadmap: {
        id: number;
        revision: number;
        status: string;
        readiness: string;
        approvedAt: string | null;
    } | null;
    summary: {
        activeAgents: number;
        ticketsTotal: number;
        ticketsByStatus: Record<string, number>;
        blockers: number;
        pendingApprovals: number;
        retriesScheduled: number;
        recentDecisions: number;
    };
    rooms: OfficeRoom[];
    agents: OfficeAgent[];
    activity?: OfficeActivity[];
    indicators: OfficeIndicator[];
    simulation: {
        labelRequired: boolean;
        executionProvider: string | null;
        actualState: string;
    };
};
