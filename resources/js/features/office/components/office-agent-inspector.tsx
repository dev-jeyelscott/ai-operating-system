import { Link } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import type { RefObject } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { officeStatePresentation } from '@/features/office/office-state-presentation';
import { officeZone } from '@/features/office/office-zone-layout';
import type { OfficeAgent } from '@/features/office/types';

type Props = {
    agent: OfficeAgent | null;
    open: boolean;
    returnFocusRef: RefObject<HTMLElement | null>;
    onOpenChange: (open: boolean) => void;
};

/**
 * Render the keyboard and screen-reader equivalent of the 3D agent inspector.
 *
 * The component receives only projected read-model data and cannot mutate
 * workflow state.
 */
export function OfficeAgentInspector({
    agent,
    open,
    returnFocusRef,
    onOpenChange,
}: Props) {
    if (!agent) {
        return null;
    }

    const presentation = officeStatePresentation(agent.officeState);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                aria-describedby="office-agent-inspector-description"
                onCloseAutoFocus={(event) => {
                    event.preventDefault();

                    queueMicrotask(() => {
                        returnFocusRef.current?.focus();
                    });
                }}
            >
                <DialogHeader>
                    <div className="flex flex-wrap items-center gap-2 pr-8">
                        <DialogTitle>{agent.role}</DialogTitle>

                        <Badge variant="outline">
                            {presentation.label}
                        </Badge>

                        {agent.provider === 'simulation' && (
                            <>
                                <Badge variant="secondary">
                                    Simulated
                                </Badge>
                                <Badge variant="outline">
                                    Unverified
                                </Badge>
                            </>
                        )}

                        {agent.approvalRequired && (
                            <Badge variant="secondary">
                                Approval required
                            </Badge>
                        )}

                        {agent.recoveryRequired && (
                            <Badge variant="destructive">
                                Recovery required
                            </Badge>
                        )}
                    </div>

                    <DialogDescription id="office-agent-inspector-description">
                        Authoritative projection details for this logical agent.
                        The 3D avatar is supplementary.
                    </DialogDescription>
                </DialogHeader>

                <dl className="grid gap-3 text-sm sm:grid-cols-2">
                    <InspectorFact
                        label="Room"
                        value={officeZone(agent.room).label}
                    />

                    <InspectorFact
                        label="Current action"
                        value={agent.currentAction}
                    />

                    <InspectorFact
                        label="Layer"
                        value={humanize(agent.layer)}
                    />

                    <InspectorFact
                        label="Capability"
                        value={humanize(agent.capability)}
                    />

                    <InspectorFact
                        label="Workflow state"
                        value={humanize(agent.workflowState)}
                    />

                    <InspectorFact
                        label="Provider state"
                        value={humanizeNullable(agent.providerState)}
                    />

                    <InspectorFact
                        label="Provider phase"
                        value={humanizeNullable(agent.providerPhase)}
                    />

                    <InspectorFact
                        label="Ticket"
                        value={agent.ticketId ?? 'None'}
                    />

                    <InspectorFact
                        label="Provider"
                        value={agent.provider ?? 'Unassigned'}
                    />

                    <InspectorFact
                        label="Model"
                        value={agent.model ?? 'Not reported'}
                    />

                    <InspectorFact
                        label="Reasoning"
                        value={
                            [
                                agent.requestedReasoning,
                                agent.effectiveReasoning,
                            ]
                                .filter(Boolean)
                                .join(' / ') || 'Not reported'
                        }
                    />

                    <InspectorFact
                        label="Attempts"
                        value={`${agent.attemptCount} of ${agent.retryLimit + 1}`}
                    />

                    <InspectorFact
                        label="Elapsed at last projection"
                        value={formatDuration(agent.elapsedSeconds)}
                    />

                    <InspectorFact
                        label="Estimated cost"
                        value={formatCost(
                            agent.estimatedCost,
                            agent.costCurrency,
                        )}
                    />

                    <InspectorFact
                        label="Actual cost"
                        value={formatCost(
                            agent.actualCost,
                            agent.costCurrency,
                        )}
                    />

                    <InspectorFact
                        label="Confidence"
                        value={agent.confidence ?? 'Not reported'}
                    />

                    <InspectorFact
                        label="Evidence state"
                        value={humanizeNullable(agent.actualState)}
                    />

                    <InspectorFact
                        label="Provider sequence"
                        value={String(agent.providerSequence ?? 0)}
                    />

                    <InspectorFact
                        label="Last provider activity"
                        value={formatDate(agent.lastProviderMessageAt ?? null)}
                    />

                    <InspectorFact
                        label="Started"
                        value={formatDate(agent.startedAt)}
                    />

                    <InspectorFact
                        label="Finished"
                        value={formatDate(agent.finishedAt)}
                    />

                    <InspectorFact
                        label="Next retry"
                        value={formatDate(agent.nextAttemptAt)}
                    />

                    <InspectorFact
                        label="Diagnostic code"
                        value={agent.diagnosticCode ?? 'None'}
                    />

                    <InspectorFact
                        label="Diagnostic"
                        value={agent.diagnosticMessage ?? 'None'}
                    />

                    <InspectorFact
                        label="Approval"
                        value={
                            agent.approvalRequired
                                ? agent.approvalSummary ??
                                  'Authorized decision required'
                                : 'None'
                        }
                    />
                </dl>

                <DialogFooter className="gap-2 sm:gap-0">
                    {agent.approvalUrl && (
                        <Button asChild variant="secondary">
                            <Link href={agent.approvalUrl}>
                                Review approval
                            </Link>
                        </Button>
                    )}

                    {agent.contextUrl && (
                        <Button asChild>
                            <Link href={agent.contextUrl}>
                                <ExternalLink aria-hidden="true" />
                                Open full context
                            </Link>
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Render one labelled inspector value.
 */
function InspectorFact({
    label,
    value,
}: {
    label: string;
    value: string;
}) {
    return (
        <div className="rounded-md border p-3">
            <dt className="font-medium">{label}</dt>
            <dd className="mt-1 break-words text-foreground">{value}</dd>
        </div>
    );
}

/**
 * Convert one enum-like string into readable text.
 */
function humanize(value: string) {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

/**
 * Convert an optional enum-like string into readable text.
 */
function humanizeNullable(value: string | null | undefined) {
    return value ? humanize(value) : 'Not reported';
}

/**
 * Format an optional duration without creating a client-side timer.
 */
function formatDuration(value: number | null | undefined) {
    if (value === null || value === undefined) {
        return 'Not available';
    }

    const minutes = Math.floor(value / 60);
    const seconds = value % 60;

    return minutes > 0
        ? `${minutes}m ${seconds}s`
        : `${seconds}s`;
}

/**
 * Format an optional monetary provider cost.
 */
function formatCost(
    value: string | null | undefined,
    currency: string | null | undefined,
) {
    if (!value) {
        return 'Not reported';
    }

    return `${currency ?? 'USD'} ${value}`;
}

/**
 * Format an optional timestamp deterministically in UTC.
 */
function formatDate(value: string | null) {
    if (!value) {
        return 'Not available';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'Unknown time';
    }

    return `${date.toISOString().slice(0, 16).replace('T', ' ')} UTC`;
}
