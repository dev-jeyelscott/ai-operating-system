import { render, screen, within } from '@testing-library/react';
import { OfficeActivityFeed } from '@/features/office/components/office-activity-feed';

describe('OfficeActivityFeed', () => {
    it('renders durable provider activity as an accessible ordered log', () => {
        render(
            <OfficeActivityFeed
                activities={[
                    {
                        sequence: 42,
                        eventId: '01KACTIVITY000000000000000',
                        executionId: '01KEXECUTION000000000000',
                        provider: 'codex',
                        state: 'planning',
                        summary: 'Generating the project plan',
                        occurredAt: '2026-08-09T14:00:00Z',
                    },
                    {
                        sequence: 43,
                        eventId: '01KACTIVITY000000000000001',
                        executionId: '01KEXECUTION000000000000',
                        provider: 'codex',
                        state: 'waiting_for_approval',
                        summary:
                            'Waiting for an authorized provider decision',
                        occurredAt: '2026-08-09T14:00:02Z',
                    },
                ]}
            />,
        );

        const log = screen.getByRole('log', {
            name: /provider planning activity/i,
        });

        expect(
            within(log).getByText('Generating the project plan'),
        ).toBeInTheDocument();

        expect(
            within(log).getByText(
                'Waiting for an authorized provider decision',
            ),
        ).toBeInTheDocument();

        expect(
            within(log).getByText(/Sequence 42/),
        ).toBeInTheDocument();

        expect(
            within(log).getByText(/Sequence 43/),
        ).toBeInTheDocument();
    });

    it('renders only the sanitized summaries it receives', () => {
        render(
            <OfficeActivityFeed
                activities={[
                    {
                        sequence: 50,
                        eventId: '01KACTIVITY000000000000050',
                        executionId: '01KEXECUTION000000000050',
                        provider: 'codex',
                        state: 'reading_documents',
                        summary: 'Reading approved planning context',
                        occurredAt: '2026-08-09T14:01:00Z',
                    },
                ]}
            />,
        );

        expect(
            screen.queryByText(/raw prompt/i),
        ).not.toBeInTheDocument();

        expect(
            screen.queryByText(/raw command/i),
        ).not.toBeInTheDocument();
    });
});
