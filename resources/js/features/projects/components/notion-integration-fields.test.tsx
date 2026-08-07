import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
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
                dataSourceCandidates={[]}
                errors={{}}
                disabled={false}
            />,
        );

        const tokenInput = screen.getByLabelText('Notion integration token');

        expect(tokenInput).toBeRequired();
        expect(tokenInput).toHaveAttribute('type', 'password');
        expect(tokenInput).toHaveAttribute('autocomplete', 'new-password');

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
                dataSourceCandidates={[]}
                errors={{}}
                disabled={false}
            />,
        );

        const tokenInput = screen.getByLabelText('Notion integration token');

        expect(tokenInput).not.toBeRequired();
        expect(tokenInput).toHaveValue('');
        expect(tokenInput).toHaveAttribute('type', 'password');
        expect(tokenInput).toHaveAttribute('autocomplete', 'new-password');

        expect(
            screen.getByText(/leave blank to test the encrypted credential/i),
        ).toBeInTheDocument();

        expect(screen.getByText('AI Operating System')).toBeInTheDocument();

        expect(screen.getByText('AIOS Tickets')).toBeInTheDocument();
    });

    it('requires an explicit selection for multiple data sources', () => {
        render(
            <NotionIntegrationFields
                integration={{
                    provider: 'notion',
                    credentialConfigured: true,
                    status: 'failed',
                    workspaceId: null,
                    workspaceName: null,
                    databaseId: 'database-id',
                    databaseName: 'Tickets',
                    lastFailureCode: null,
                    lastTestedAt: null,
                    lastConnectedAt: null,
                }}
                dataSourceCandidates={[
                    {
                        id: '11111111-1111-4111-8111-111111111111',
                        name: 'Product tickets',
                    },
                    {
                        id: '22222222-2222-4222-8222-222222222222',
                        name: 'Operations tickets',
                    },
                ]}
                errors={{ data_source_id: 'Select one source.' }}
                disabled={false}
            />,
        );

        expect(
            screen.getByRole('combobox', {
                name: 'Notion task data source',
            }),
        ).toHaveAttribute('aria-required', 'true');
        expect(
            screen.getByText(/multiple eligible data sources/i),
        ).toBeInTheDocument();
        expect(screen.getByText('Select one source.')).toBeInTheDocument();
    });

    it('submits the selected data source through the surrounding form', async () => {
        const user = userEvent.setup();

        const { container } = render(
            <form>
                <NotionIntegrationFields
                    integration={{
                        provider: 'notion',
                        credentialConfigured: true,
                        status: 'failed',
                        workspaceId: null,
                        workspaceName: null,
                        databaseId: 'database-id',
                        databaseName: 'Tickets',
                        lastFailureCode: null,
                        lastTestedAt: null,
                        lastConnectedAt: null,
                    }}
                    dataSourceCandidates={[
                        {
                            id: '11111111-1111-4111-8111-111111111111',
                            name: 'Product tickets',
                        },
                        {
                            id: '22222222-2222-4222-8222-222222222222',
                            name: 'Operations tickets',
                        },
                    ]}
                    errors={{}}
                    disabled={false}
                />
            </form>,
        );

        const form = container.querySelector('form') as HTMLFormElement;
        const dataSourceInput = form.querySelector<HTMLSelectElement>(
            'select[name="data_source_id"]',
        );

        expect(dataSourceInput).toBeRequired();

        await user.selectOptions(
            dataSourceInput as HTMLSelectElement,
            '11111111-1111-4111-8111-111111111111',
        );

        expect(new FormData(form).get('data_source_id')).toBe(
            '11111111-1111-4111-8111-111111111111',
        );
    });
});
