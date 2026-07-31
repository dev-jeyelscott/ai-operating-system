import {
    OFFICE_ZONE_LAYOUT,
    OFFICE_ZONE_ORDER,
} from '@/features/office/office-zone-layout';

describe('office zone layout', () => {
    it('contains every authoritative MVP office room', () => {
        expect(OFFICE_ZONE_ORDER).toEqual([
            'lobby',
            'planning_room',
            'development_floor',
            'qa_laboratory',
            'approval_room',
            'operations_area',
            'archive',
        ]);

        expect(Object.keys(OFFICE_ZONE_LAYOUT).sort()).toEqual(
            [...OFFICE_ZONE_ORDER].sort(),
        );
    });

    it('uses unique room positions', () => {
        const positions = OFFICE_ZONE_ORDER.map((key) =>
            OFFICE_ZONE_LAYOUT[key].position.join(','),
        );

        expect(new Set(positions).size).toBe(positions.length);
    });

    it('defines a camera pose for every room', () => {
        for (const key of OFFICE_ZONE_ORDER) {
            expect(OFFICE_ZONE_LAYOUT[key].cameraPosition).toHaveLength(3);

            expect(OFFICE_ZONE_LAYOUT[key].cameraTarget).toHaveLength(3);
        }
    });
});
