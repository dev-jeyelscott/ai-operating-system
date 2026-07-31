import { buildAgentPositions } from '@/features/office/agent-layout';
import { officeProjectionFixture } from '@/tests/fixtures/office-projection';

describe('buildAgentPositions', () => {
    it('places agents inside their authoritative room', () => {
        const projection = officeProjectionFixture();
        const positions = buildAgentPositions(projection.agents);

        expect(positions['01KEXECUTION000000000000000']).toEqual([0, 0.65, -6]);
    });

    it('is deterministic regardless of input order', () => {
        const projection = officeProjectionFixture();
        const first = projection.agents[0];

        const second = {
            ...first,
            id: '01KEXECUTION000000000000001',
            role: 'Test Engineer',
        };

        const forward = buildAgentPositions([first, second]);
        const reverse = buildAgentPositions([second, first]);

        expect(reverse).toEqual(forward);
    });

    it('assigns unique positions within one room', () => {
        const projection = officeProjectionFixture();
        const first = projection.agents[0];

        const positions = buildAgentPositions([
            first,
            {
                ...first,
                id: '01KEXECUTION000000000000001',
            },
            {
                ...first,
                id: '01KEXECUTION000000000000002',
            },
        ]);

        const serialized = Object.values(positions).map((position) =>
            position.join(','),
        );

        expect(new Set(serialized).size).toBe(serialized.length);
    });
});
