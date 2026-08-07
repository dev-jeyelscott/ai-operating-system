import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    NotionDataSourceCandidate,
    ProjectIntegrationConnection,
} from '@/types';

type Props = {
    integration: ProjectIntegrationConnection;
    dataSourceCandidates: NotionDataSourceCandidate[];
    errors: Record<string, string | undefined>;
    disabled: boolean;
};

/**
 * Render the credential and database fields used by the read-only Notion test.
 */
export function NotionIntegrationFields({
    integration,
    dataSourceCandidates,
    errors,
    disabled,
}: Props) {
    const connected = integration.status === 'connected';

    return (
        <div className="grid gap-6">
            <div className="grid gap-2">
                <Label htmlFor="notion-credential">
                    Notion integration token
                </Label>

                <Input
                    id="notion-credential"
                    name="credential"
                    type="password"
                    autoComplete="new-password"
                    disabled={disabled}
                    required={!integration.credentialConfigured}
                    aria-invalid={Boolean(errors.credential)}
                    aria-describedby="notion-credential-help"
                />

                <p
                    id="notion-credential-help"
                    className="text-sm text-muted-foreground"
                >
                    {integration.credentialConfigured
                        ? 'Leave blank to test the encrypted credential already stored for this project. Enter a value only when rotating the token.'
                        : 'The token is encrypted before storage and is never returned to the browser.'}
                </p>

                <InputError
                    id="notion-credential-error"
                    message={errors.credential}
                />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="notion-database-id">Notion task database</Label>

                <Input
                    id="notion-database-id"
                    name="database_id"
                    defaultValue={integration.databaseId ?? ''}
                    placeholder="Paste the database URL or UUID"
                    disabled={disabled}
                    required
                    spellCheck={false}
                    aria-invalid={Boolean(errors.database_id)}
                    aria-describedby="notion-database-help"
                />

                <p
                    id="notion-database-help"
                    className="text-sm text-muted-foreground"
                >
                    Share the original database with the Notion integration
                    before running the test.
                </p>

                <InputError
                    id="notion-database-error"
                    message={errors.database_id}
                />
            </div>

            <InputError
                id="notion-connection-error"
                message={errors.connection}
            />

            {dataSourceCandidates.length > 0 && (
                <div className="grid gap-2">
                    <Label htmlFor="notion-data-source-id">
                        Notion task data source
                    </Label>
                    <Select name="data_source_id" required disabled={disabled}>
                        <SelectTrigger
                            id="notion-data-source-id"
                            aria-invalid={Boolean(errors.data_source_id)}
                        >
                            <SelectValue placeholder="Select a data source" />
                        </SelectTrigger>
                        <SelectContent>
                            {dataSourceCandidates.map((candidate) => (
                                <SelectItem
                                    key={candidate.id}
                                    value={candidate.id}
                                >
                                    {candidate.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <p className="text-sm text-muted-foreground">
                        This database has multiple eligible data sources. Select
                        the one that stores roadmap tickets.
                    </p>
                    <InputError
                        id="notion-data-source-id-error"
                        message={errors.data_source_id}
                    />
                </div>
            )}

            {integration.status !== null && (
                <div className="rounded-lg border bg-muted/30 p-4">
                    <dl className="grid gap-3 text-sm md:grid-cols-2">
                        <div>
                            <dt className="text-muted-foreground">
                                Connection
                            </dt>
                            <dd className="font-medium">
                                {connected ? 'Connected' : 'Failed'}
                            </dd>
                        </div>

                        <div>
                            <dt className="text-muted-foreground">Workspace</dt>
                            <dd className="font-medium">
                                {integration.workspaceName ??
                                    integration.workspaceId ??
                                    'Unavailable'}
                            </dd>
                        </div>

                        <div>
                            <dt className="text-muted-foreground">Database</dt>
                            <dd className="font-medium">
                                {integration.databaseName ??
                                    integration.databaseId ??
                                    'Unavailable'}
                            </dd>
                        </div>

                        <div>
                            <dt className="text-muted-foreground">
                                Last tested
                            </dt>
                            <dd className="font-medium">
                                {integration.lastTestedAt
                                    ? new Date(
                                          integration.lastTestedAt,
                                      ).toLocaleString()
                                    : 'Not tested'}
                            </dd>
                        </div>
                    </dl>
                </div>
            )}

            <p className="text-sm text-muted-foreground">
                The connection test performs read-only Notion requests. It does
                not create, edit, query, or delete ticket pages.
            </p>
        </div>
    );
}
