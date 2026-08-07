import type { OfficeRoomKey } from '@/features/office/types';

export type Vector3Tuple = [number, number, number];

export type OfficeZoneDefinition = {
    key: OfficeRoomKey;
    label: string;
    position: Vector3Tuple;
    size: Vector3Tuple;
    cameraPosition: Vector3Tuple;
    cameraTarget: Vector3Tuple;
};

/**
 * Keep the visual room order stable across the DOM navigator, scene, and tests.
 */
export const OFFICE_ZONE_ORDER: OfficeRoomKey[] = [
    'lobby',
    'planning_room',
    'development_floor',
    'qa_laboratory',
    'approval_room',
    'operations_area',
    'archive',
];

/**
 * Define one simple low-poly floor plan for the MVP.
 *
 * Coordinates are presentation-only and do not represent workflow truth.
 */
export const OFFICE_ZONE_LAYOUT: Record<OfficeRoomKey, OfficeZoneDefinition> = {
    lobby: {
        key: 'lobby',
        label: 'Lobby',
        position: [0, 0, 0],
        size: [5.5, 0.3, 3.5],
        cameraPosition: [0, 5.5, 8],
        cameraTarget: [0, 0, 0],
    },
    planning_room: {
        key: 'planning_room',
        label: 'Planning Room',
        position: [-7, 0, -5],
        size: [5.5, 0.3, 4],
        cameraPosition: [-7, 5.5, 2],
        cameraTarget: [-7, 0, -5],
    },
    development_floor: {
        key: 'development_floor',
        label: 'Development Floor',
        position: [0, 0, -6],
        size: [7, 0.3, 5],
        cameraPosition: [0, 6, 2],
        cameraTarget: [0, 0, -6],
    },
    qa_laboratory: {
        key: 'qa_laboratory',
        label: 'QA Laboratory',
        position: [7, 0, -5],
        size: [5.5, 0.3, 4],
        cameraPosition: [7, 5.5, 2],
        cameraTarget: [7, 0, -5],
    },
    approval_room: {
        key: 'approval_room',
        label: 'Approval Room',
        position: [-7, 0, 2],
        size: [5.5, 0.3, 4],
        cameraPosition: [-7, 5.5, 9],
        cameraTarget: [-7, 0, 2],
    },
    operations_area: {
        key: 'operations_area',
        label: 'Operations Area',
        position: [7, 0, 2],
        size: [5.5, 0.3, 4],
        cameraPosition: [7, 5.5, 9],
        cameraTarget: [7, 0, 2],
    },
    archive: {
        key: 'archive',
        label: 'Completed Work',
        position: [0, 0, 5],
        size: [6, 0.3, 3],
        cameraPosition: [0, 5, 12],
        cameraTarget: [0, 0, 5],
    },
};

/**
 * Return the required visual definition for one authoritative room key.
 */
export function officeZone(key: OfficeRoomKey): OfficeZoneDefinition {
    return OFFICE_ZONE_LAYOUT[key];
}
