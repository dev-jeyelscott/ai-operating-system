import { Link } from '@inertiajs/react';
import { ExternalLink, LocateFixed } from 'lucide-react';
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
    onSelectRoom: (room: OfficeRoomKey) => void;
};

/**
 * Render the accessible equivalent of every projected logical-agent avatar.
 */
export function AgentStatusPanel({
    agents,
    selectedRoom,
    onSelectRoom,
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
                            const selected = selectedRoom === agent.room;

                            return (
                                <li
                                    key={agent.id}
                                    className="rounded-lg border p-4"
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
                                                <div>
                                                    <dt className="inline font-medium text-foreground">
                                                        Room:
                                                    </dt>{' '}
                                                    <dd className="inline">
                                                        {
                                                            officeZone(
                                                                agent.room,
                                                            ).label
                                                        }
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt className="inline font-medium text-foreground">
                                                        Ticket:
                                                    </dt>{' '}
                                                    <dd className="inline">
                                                        {agent.ticketId ??
                                                            'None'}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt className="inline font-medium text-foreground">
                                                        Provider:
                                                    </dt>{' '}
                                                    <dd className="inline">
                                                        {agent.provider ??
                                                            'Unassigned'}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt className="inline font-medium text-foreground">
                                                        Reasoning:
                                                    </dt>{' '}
                                                    <dd className="inline">
                                                        {
                                                            agent.requestedReasoning
                                                        }
                                                        {agent.effectiveReasoning
                                                            ? ` / ${agent.effectiveReasoning}`
                                                            : ''}
                                                    </dd>
                                                </div>
                                            </dl>
                                        </div>

                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant={
                                                    selected
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                aria-pressed={selected}
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
