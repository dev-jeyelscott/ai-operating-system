import {
    OFFICE_ZONE_ORDER,
    officeZone,
} from '@/features/office/office-zone-layout';
import type { Vector3Tuple } from '@/features/office/office-zone-layout';
import type { OfficeAgent, OfficeRoomKey } from '@/features/office/types';

/**
 * Calculate deterministic avatar positions from authoritative room assignment.
 *
 * Agents are sorted by stable execution ID so projection refreshes do not
 * randomly reshuffle avatars.
 */
export function buildAgentPositions(
    agents: OfficeAgent[],
): Record<string, Vector3Tuple> {
    const positions: Record<string, Vector3Tuple> = {};
    const agentsByRoom = groupAgentsByRoom(agents);

    for (const roomKey of OFFICE_ZONE_ORDER) {
        const roomAgents = [...(agentsByRoom.get(roomKey) ?? [])].sort(
            (left, right) => left.id.localeCompare(right.id),
        );

        const definition = officeZone(roomKey);
        const columns = Math.max(
            1,
            Math.min(3, Math.ceil(Math.sqrt(roomAgents.length))),
        );
        const rows = Math.max(1, Math.ceil(roomAgents.length / columns));

        const spacingX = Math.min(
            1.5,
            Math.max(0.9, (definition.size[0] - 1) / columns),
        );
        const spacingZ = Math.min(
            1.35,
            Math.max(0.85, (definition.size[2] - 1) / rows),
        );

        roomAgents.forEach((agent, index) => {
            const column = index % columns;
            const row = Math.floor(index / columns);

            const xOffset = (column - (columns - 1) / 2) * spacingX;
            const zOffset = (row - (rows - 1) / 2) * spacingZ;

            positions[agent.id] = [
                definition.position[0] + xOffset,
                0.65,
                definition.position[2] + zOffset,
            ];
        });
    }

    return positions;
}

/**
 * Group authoritative agents by their projected room.
 */
function groupAgentsByRoom(
    agents: OfficeAgent[],
): Map<OfficeRoomKey, OfficeAgent[]> {
    const groups = new Map<OfficeRoomKey, OfficeAgent[]>();

    for (const agent of agents) {
        const roomAgents = groups.get(agent.room) ?? [];
        roomAgents.push(agent);
        groups.set(agent.room, roomAgents);
    }

    return groups;
}
