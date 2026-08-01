import { Link } from '@inertiajs/react';
import { ExternalLink, Eye, LocateFixed } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { officeStatePresentation } from '@/features/office/office-state-presentation';
import { officeZone } from '@/features/office/office-zone-layout';
import type { OfficeAgent, OfficeRoomKey } from '@/features/office/types';

type Props = {
    agents: OfficeAgent[];
    selectedRoom: OfficeRoomKey;
    selectedAgentId: string | null;
    onSelectRoom: (room: OfficeRoomKey) => void;
    onInspectAgent: (agentId: string, trigger: HTMLButtonElement) => void;
};

/**
 * Render the accessible equivalent of every projected logical-agent avatar.
 */
export function AgentStatusPanel({
    agents,
    selectedRoom,
    selectedAgentId,
    onSelectRoom,
    onInspectAgent,
}: Props) {
    const orderedAgents = [...agents].sort(
        (left, right) =>
            left.role.localeCompare(right.role) ||
            left.id.localeCompare(right.id),
    );

    return (
        <Card>
            <CardHeader>
                <CardTitle id="logical-agents-heading">
                    Logical agents
                </CardTitle>
                <CardDescription>
                    Authoritative role and state presentation. The 3D avatar is
                    supplementary.
                </CardDescription>
            </CardHeader>

            <CardContent>
                {orderedAgents.length === 0 ? (
                    <p className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                        No workflow executions are currently projected as
                        logical agents.
                    </p>
                ) : (
                    <ul
                        aria-labelledby="logical-agents-heading"
                        className="grid gap-3 lg:grid-cols-2"
                    >
                        {orderedAgents.map((agent) => {
                            const presentation = officeStatePresentation(
                                agent.officeState,
                            );
                            const roomSelected = selectedRoom === agent.room;
                            const agentSelected = selectedAgentId === agent.id;

                            return (
                                <li
                                    key={agent.id}
                                    className="rounded-lg border p-4"
                                    data-office-agent-id={agent.id}
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <p className="font-medium">
                                                    {agent.role}
                                                </p>
                                                <Badge variant="outline">
                                                    {presentation.label}
                                                </Badge>
                                                {agent.provider ===
                                                    'simulation' && (
                                                    <>
                                                        <Badge variant="secondary">
                                                            Simulated
                                                        </Badge>
                                                        <Badge variant="outline">
                                                            Unverified
                                                        </Badge>
                                                    </>
                                                )}
                                            </div>

                                            <p className="mt-2 text-sm text-muted-foreground">
                                                {agent.currentAction}
                                            </p>

                                            <dl className="mt-3 grid gap-1 text-xs text-muted-foreground">
                                                <InlineFact
                                                    label="Room"
                                                    value={
                                                        officeZone(agent.room)
                                                            .label
                                                    }
                                                />
                                                <InlineFact
                                                    label="Ticket"
                                                    value={
                                                        agent.ticketId ?? 'None'
                                                    }
                                                />
                                                <InlineFact
                                                    label="Provider"
                                                    value={
                                                        agent.provider ??
                                                        'Unassigned'
                                                    }
                                                />
                                                <InlineFact
                                                    label="Reasoning"
                                                    value={[
                                                        agent.requestedReasoning,
                                                        agent.effectiveReasoning,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' / ')}
                                                />
                                            </dl>
                                        </div>

                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant={
                                                    agentSelected
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                aria-haspopup="dialog"
                                                aria-expanded={agentSelected}
                                                data-office-agent-inspect={
                                                    agent.id
                                                }
                                                onClick={(event) =>
                                                    onInspectAgent(
                                                        agent.id,
                                                        event.currentTarget,
                                                    )
                                                }
                                            >
                                                <Eye aria-hidden="true" />
                                                Inspect agent
                                            </Button>

                                            <Button
                                                type="button"
                                                size="sm"
                                                variant={
                                                    roomSelected
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                aria-pressed={roomSelected}
                                                onClick={() =>
                                                    onSelectRoom(agent.room)
                                                }
                                            >
                                                <LocateFixed aria-hidden="true" />
                                                Focus room
                                            </Button>

                                            {agent.contextUrl && (
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="outline"
                                                >
                                                    <Link
                                                        href={agent.contextUrl}
                                                    >
                                                        <ExternalLink aria-hidden="true" />
                                                        Open context
                                                    </Link>
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

/**
 * Render one compact definition-list value.
 */
function InlineFact({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="inline font-medium text-foreground">{label}:</dt>{' '}
            <dd className="inline">{value}</dd>
        </div>
    );
}
