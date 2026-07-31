import { officeStatePresentation } from '@/features/office/office-state-presentation';

describe('officeStatePresentation', () => {
    it.each([
        ['implementing', 'Implementing'],
        ['reviewing', 'Reviewing'],
        ['blocked', 'Blocked'],
        ['retrying', 'Retrying'],
        ['waiting_for_human', 'Waiting for human'],
        ['completed', 'Completed'],
        ['failed', 'Failed'],
    ])('maps %s to %s', (state, expectedLabel) => {
        expect(officeStatePresentation(state).label).toBe(expectedLabel);
    });

    it('degrades an unknown value to idle presentation', () => {
        expect(officeStatePresentation('unexpected_state').label).toBe('Idle');
    });
});
