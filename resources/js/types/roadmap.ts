export type RoadmapSourceReference = {
    criterionStableId: string;
    documentId: number;
    documentVersionId: number;
    documentVersion: number;
    checksumSha256: string;
    documentName: string;
};

export type RoadmapTask = {
    id: number;
    stableId: string;
    title: string;
    objective: string;
    phaseId: number;
    phaseName: string | null;
    milestoneName: string | null;
    ticketType: string;
    scope: { included: string[]; excluded: string[] };
    acceptanceCriteria: Array<{
        stable_id: string;
        description: string;
        source_references: unknown[];
    }>;
    evidenceRequirements: string[];
    priority: string;
    risk: string;
    reasoningLevel: string;
    reasoning: string;
    logicalAgent: string;
    estimatedComplexity: number;
    humanApprovalRequired: boolean;
    criticalPathRank: number | null;
    criticalPathPosition: number | null;
    isCriticalPath: boolean;
    dependencies: Array<{ id: number; stableId: string; title: string }>;
    traceability: RoadmapSourceReference[];
    notionMapping: {
        mappingId: number;
        externalKey: string;
        pageUrl: string | null;
        state: string;
        reconciliationState: string | null;
        retryable: boolean;
    } | null;
};

export type NotionPublicationView = {
    readiness: boolean;
    schemaReadiness: 'ready' | 'incompatible' | 'unverified' | 'not_configured';
    dataSourceName: string | null;
    dataSourceId: string | null;
    summary: {
        createdCount: number;
        updatedCount: number;
        skippedCount: number;
        failedCount: number;
        conflictedCount: number;
        completedAt: string | null;
        diagnostics: string[];
    } | null;
};

export type RoadmapView = {
    id: number;
    revision: number;
    contentVersion: number;
    candidateFingerprint: string;
    outputFingerprint: string;
    status: string;
    isLatest: boolean;
    readiness: string;
    readinessReasons: string[];
    goal: string;
    scope: string[];
    assumptions: string[];
    constraints: string[];
    definitionOfDone: string[];
    requiredApprovals: string[];
    documentSummary: string;
    documentInventory: Array<{
        document_id: number;
        document_version_id: number;
        version: number;
        checksum_sha256: string;
        classification: string;
        summary: string;
    }>;
    architectureConcerns: string[];
    securityConcerns: string[];
    criticalPath: string[];
    comparison: {
        generatedGoal: string | null;
        candidateGoal: string | null;
        approvedGoal: string | null;
        generatedFingerprint: string;
        candidateFingerprint: string;
        approvedFingerprint: string | null;
    };
    approval: { id: string; status: string; reason: string | null } | null;
    phases: Array<{
        id: number;
        stableId: string;
        name: string;
        milestones: Array<{ id: number; stableId: string; name: string }>;
    }>;
    tasks: RoadmapTask[];
    reverseTraceability: Array<{
        documentVersionId: number;
        documentName: string;
        documentVersion: number;
        tasks: Array<{ taskId: number; criterionStableId: string }>;
    }>;
    edits: Array<{
        contentVersion: number;
        actorName: string;
        createdAt: string;
    }>;
};
