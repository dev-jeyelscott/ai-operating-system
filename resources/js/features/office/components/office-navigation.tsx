import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    OFFICE_ZONE_ORDER,
    officeZone,
} from '@/features/office/office-zone-layout';
import type { OfficeRoom, OfficeRoomKey } from '@/features/office/types';

type Props = {
    rooms: OfficeRoom[];
    selectedRoom: OfficeRoomKey;
    onSelectRoom: (room: OfficeRoomKey) => void;
};

/**
 * Render the keyboard-accessible equivalent of room selection.
 */
export function OfficeNavigation({ rooms, selectedRoom, onSelectRoom }: Props) {
    const roomsByKey = new Map(rooms.map((room) => [room.key, room]));

    return (
        <Card>
            <CardHeader>
                <CardTitle id="office-zone-navigation-heading">
                    Office zones
                </CardTitle>
            </CardHeader>

            <CardContent>
                <nav
                    aria-labelledby="office-zone-navigation-heading"
                    className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                >
                    {OFFICE_ZONE_ORDER.map((roomKey) => {
                        const room = roomsByKey.get(roomKey);

                        if (!room) {
                            return null;
                        }

                        const definition = officeZone(roomKey);
                        const selected = selectedRoom === roomKey;

                        return (
                            <Button
                                key={roomKey}
                                type="button"
                                variant={selected ? 'default' : 'outline'}
                                className="h-auto min-h-20 justify-start p-4 text-left whitespace-normal"
                                aria-pressed={selected}
                                onClick={() => onSelectRoom(roomKey)}
                            >
                                <span className="flex w-full flex-col gap-2">
                                    <span className="flex items-start justify-between gap-2">
                                        <span className="font-medium">
                                            {definition.label}
                                        </span>
                                        <Badge
                                            variant={
                                                room.actionableCount > 0
                                                    ? 'destructive'
                                                    : 'secondary'
                                            }
                                        >
                                            {humanize(room.state)}
                                        </Badge>
                                    </span>

                                    <span className="text-xs opacity-80">
                                        {room.activeAgents} active agent
                                        {room.activeAgents === 1 ? '' : 's'}
                                        {' · '}
                                        {room.actionableCount} action
                                        {room.actionableCount === 1 ? '' : 's'}
                                    </span>
                                </span>
                            </Button>
                        );
                    })}
                </nav>
            </CardContent>
        </Card>
    );
}

/**
 * Convert enum-style values into readable labels.
 */
function humanize(value: string) {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}
