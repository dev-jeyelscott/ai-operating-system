import { useMemo, useRef } from 'react';
import type { KeyboardEvent } from 'react';
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
 *
 * The toolbar uses one tab stop. Arrow keys move focus and selection inside
 * the room collection.
 */
export function OfficeNavigation({ rooms, selectedRoom, onSelectRoom }: Props) {
    const buttonRefs = useRef(new Map<OfficeRoomKey, HTMLButtonElement>());

    const roomsByKey = useMemo(
        () => new Map(rooms.map((room) => [room.key, room])),
        [rooms],
    );

    const visibleRoomKeys = useMemo(
        () => OFFICE_ZONE_ORDER.filter((roomKey) => roomsByKey.has(roomKey)),
        [roomsByKey],
    );

    /**
     * Select and focus one room control.
     */
    function selectAndFocus(roomKey: OfficeRoomKey) {
        onSelectRoom(roomKey);

        queueMicrotask(() => {
            buttonRefs.current.get(roomKey)?.focus();
        });
    }

    /**
     * Apply toolbar keyboard conventions without adding global shortcuts.
     */
    function handleKeyDown(
        event: KeyboardEvent<HTMLButtonElement>,
        roomKey: OfficeRoomKey,
    ) {
        const currentIndex = visibleRoomKeys.indexOf(roomKey);

        if (currentIndex < 0) {
            return;
        }

        let targetIndex: number | null = null;

        switch (event.key) {
            case 'ArrowRight':
            case 'ArrowDown':
                targetIndex = (currentIndex + 1) % visibleRoomKeys.length;
                break;

            case 'ArrowLeft':
            case 'ArrowUp':
                targetIndex =
                    (currentIndex - 1 + visibleRoomKeys.length) %
                    visibleRoomKeys.length;
                break;

            case 'Home':
                targetIndex = 0;
                break;

            case 'End':
                targetIndex = visibleRoomKeys.length - 1;
                break;
        }

        if (targetIndex === null) {
            return;
        }

        event.preventDefault();
        selectAndFocus(visibleRoomKeys[targetIndex]);
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle id="office-zone-navigation-heading">
                    Office zones
                </CardTitle>
            </CardHeader>

            <CardContent>
                <nav aria-label="Office room navigation">
                    <div
                        role="toolbar"
                        aria-labelledby="office-zone-navigation-heading"
                        aria-orientation="horizontal"
                        className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                    >
                        {visibleRoomKeys.map((roomKey) => {
                            const room = roomsByKey.get(roomKey);

                            if (!room) {
                                return null;
                            }

                            const definition = officeZone(roomKey);
                            const selected = selectedRoom === roomKey;

                            return (
                                <Button
                                    key={roomKey}
                                    ref={(element) => {
                                        if (element) {
                                            buttonRefs.current.set(
                                                roomKey,
                                                element,
                                            );
                                        } else {
                                            buttonRefs.current.delete(roomKey);
                                        }
                                    }}
                                    type="button"
                                    variant={selected ? 'default' : 'outline'}
                                    className="h-auto min-h-20 justify-start p-4 text-left whitespace-normal"
                                    tabIndex={selected ? 0 : -1}
                                    aria-pressed={selected}
                                    aria-controls="office-canvas-region"
                                    data-office-room-control={roomKey}
                                    onClick={() => onSelectRoom(roomKey)}
                                    onKeyDown={(event) =>
                                        handleKeyDown(event, roomKey)
                                    }
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
                                            {room.actionableCount === 1
                                                ? ''
                                                : 's'}
                                        </span>
                                    </span>
                                </Button>
                            );
                        })}
                    </div>
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
