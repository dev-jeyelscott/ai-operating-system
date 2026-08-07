export type OfficeStatePresentation = {
    label: string;
    color: string;
    emissive: string;
    emissiveIntensity: number;
    ringColor: string;
};

/**
 * Resolve visual presentation from the authoritative backend office state.
 *
 * Unknown values intentionally degrade to Idle instead of inventing progress.
 */
export function officeStatePresentation(
    state: string,
): OfficeStatePresentation {
    switch (state) {
        case 'reading_documents':
            return presentation('Reading documents', '#a78bfa', '#4c1d95');
        case 'planning':
            return presentation('Planning', '#8b5cf6', '#4c1d95');
        case 'waiting_for_approval':
        case 'waiting_for_human':
            return presentation('Waiting for human', '#eab308', '#713f12');
        case 'selecting_ticket':
            return presentation('Selecting ticket', '#06b6d4', '#164e63');
        case 'implementing':
        case 'working':
            return presentation('Implementing', '#3b82f6', '#1e3a8a');
        case 'validating':
            return presentation('Validating', '#14b8a6', '#134e4a');
        case 'creating_pull_request':
            return presentation('Creating pull request', '#2dd4bf', '#115e59');
        case 'reviewing':
            return presentation('Reviewing', '#ec4899', '#831843');
        case 'blocked':
            return presentation('Blocked', '#ef4444', '#7f1d1d', 0.7);
        case 'retrying':
            return presentation('Retrying', '#f97316', '#7c2d12', 0.65);
        case 'completed':
            return presentation('Completed', '#22c55e', '#14532d');
        case 'failed':
            return presentation('Failed', '#dc2626', '#7f1d1d', 0.8);
        case 'queued':
            return presentation('Queued', '#94a3b8', '#334155', 0.25);
        default:
            return presentation('Idle', '#71717a', '#27272a', 0.2);
    }
}

/**
 * Build one complete material presentation record.
 */
function presentation(
    label: string,
    color: string,
    emissive: string,
    emissiveIntensity = 0.45,
): OfficeStatePresentation {
    return {
        label,
        color,
        emissive,
        emissiveIntensity,
        ringColor: color,
    };
}
