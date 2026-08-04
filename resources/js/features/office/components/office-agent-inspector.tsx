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
 * The component receives only projected read-model data. It cannot mutate
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

                        <Badge variant="outline">{presentation.label}</Badge>

                        {agent.provider === 'simulation' && (
                            <>
                                <Badge variant="secondary">Simulated</Badge>
                                <Badge variant="outline">Unverified</Badge>
                            </>
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
                        label="Ticket"
                        value={agent.ticketId ?? 'None'}
                    />
                    <InspectorFact
                        label="Provider"
                        value={agent.provider ?? 'Unassigned'}
                    />
                    <InspectorFact
                        label="Reasoning"
                        value={[
                            agent.requestedReasoning,
                            agent.effectiveReasoning,
                        ]
                            .filter(Boolean)
                            .join(' / ')}
                    />
                    <InspectorFact
                        label="Attempts"
                        value={`${agent.attemptCount} of ${agent.retryLimit}`}
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
                </dl>

                <DialogFooter>
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
 * Render one labelled inspector value with sufficient contrast for critical
 * operational information.
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
            <dd className="mt-1 break-words text-foreground">
                {value}
            </dd>
        </div>
    );
}

/**
 * Convert an enum-like value into a readable label.
 */
function humanize(value: string) {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
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
