import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { NotionIntegrationFields } from './notion-integration-fields';

describe('NotionIntegrationFields', () => {
    it('requires a token when no credential is stored', () => {
        render(
            <NotionIntegrationFields
                integration={{
                    provider: 'notion',
                    credentialConfigured: false,
                    status: null,
                    workspaceId: null,
                    workspaceName: null,
                    databaseId: null,
                    databaseName: null,
                    lastFailureCode: null,
                    lastTestedAt: null,
                    lastConnectedAt: null,
                }}
                errors={{}}
                disabled={false}
            />,
        );

        expect(
            screen.getByLabelText('Notion integration token'),
        ).toBeRequired();

        expect(screen.getByLabelText('Notion task database')).toBeRequired();
    });

    it('does not redisplay an existing credential', () => {
        render(
            <NotionIntegrationFields
                integration={{
                    provider: 'notion',
                    credentialConfigured: true,
                    status: 'connected',
                    workspaceId: '17ab3186-873d-418f-b899-c3f6a43f68de',
                    workspaceName: 'AI Operating System',
                    databaseId: 'd9824bdc-8445-4327-be8b-5b47500af6ce',
                    databaseName: 'AIOS Tickets',
                    lastFailureCode: null,
                    lastTestedAt: '2026-07-20T10:00:00+08:00',
                    lastConnectedAt: '2026-07-20T10:00:00+08:00',
                }}
                errors={{}}
                disabled={false}
            />,
        );

        const tokenInput = screen.getByLabelText('Notion integration token');

        expect(tokenInput).not.toBeRequired();
        expect(tokenInput).toHaveValue('');

        expect(screen.getByText('AI Operating System')).toBeInTheDocument();

        expect(screen.getByText('AIOS Tickets')).toBeInTheDocument();
    });
});
