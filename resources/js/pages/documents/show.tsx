import { Form, Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type DocumentVersion = {
    id: number;
    version: number;
    status: string;
    classification: string;
    checksum: string;
    parserVersion: string | null;
    analyzerName: string | null;
    analyzerVersion: string | null;
    analysisSeed: number | null;
    analysisCompletedAt: string | null;
    notes: string | null;
    flags: string[];
    replacementUrl: string | null;
};

type Props = {
    document: {
        title: string;
        versions: DocumentVersion[];
    };
    permissions: {
        replace: boolean;
    };
    urls: {
        index: string;
    };
};

/**
 * Display immutable document revisions and replacement controls.
 */
export default function DocumentShow({ document, permissions, urls }: Props) {
    return (
        <>
            <Head title={document.title} />

            <main className="space-y-6 p-6">
                <Link href={urls.index}>Back to documents</Link>

                <div>
                    <h1 className="text-2xl font-semibold">{document.title}</h1>

                    <p className="mt-2 text-sm text-muted-foreground">
                        Replacement uploads create a new quarantined revision.
                        The currently approved version remains authoritative
                        until the replacement is reviewed and approved.
                    </p>
                </div>

                {document.versions.map((version) => {
                    const fileInputId = `replacement-${version.id}`;

                    return (
                        <section
                            key={version.id}
                            className="rounded-xl border p-4"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <h2 className="font-semibold">
                                    Version {version.version}
                                </h2>

                                <span className="rounded-full border px-2.5 py-1 text-xs font-medium">
                                    {version.status}
                                </span>
                            </div>

                            <dl className="mt-3 grid gap-2 text-sm">
                                <div>
                                    Classification: {version.classification}
                                </div>

                                <div className="break-all">
                                    Checksum: <code>{version.checksum}</code>
                                </div>

                                <div>
                                    Parser: {version.parserVersion ?? 'Pending'}
                                </div>

                                <div>
                                    Analyzer:{' '}
                                    {version.analyzerName &&
                                    version.analyzerVersion
                                        ? `${version.analyzerName}@${version.analyzerVersion}`
                                        : 'Pending'}
                                </div>

                                <div>
                                    Analysis seed:{' '}
                                    {version.analysisSeed ?? 'Pending'}
                                </div>

                                <div>
                                    Analysis completed:{' '}
                                    {version.analysisCompletedAt
                                        ? new Date(
                                              version.analysisCompletedAt,
                                          ).toLocaleString()
                                        : 'Pending'}
                                </div>

                                <div>Notes: {version.notes ?? 'None'}</div>

                                <div>
                                    Safety flags:{' '}
                                    {version.flags.length > 0
                                        ? version.flags.join(', ')
                                        : 'None'}
                                </div>
                            </dl>

                            {permissions.replace &&
                                version.status === 'approved' &&
                                version.replacementUrl && (
                                    <Form
                                        action={version.replacementUrl}
                                        method="post"
                                        className="mt-5 space-y-3 rounded-lg border bg-muted/30 p-4"
                                    >
                                        {({ errors, processing }) => (
                                            <>
                                                <div>
                                                    <label
                                                        htmlFor={fileInputId}
                                                        className="text-sm font-medium"
                                                    >
                                                        Replacement file for
                                                        version{' '}
                                                        {version.version}
                                                    </label>

                                                    <input
                                                        id={fileInputId}
                                                        name="document"
                                                        type="file"
                                                        accept=".md,.txt,text/markdown,text/plain"
                                                        required
                                                        className="mt-2 block w-full text-sm"
                                                        aria-describedby={
                                                            errors.document
                                                                ? `${fileInputId}-error`
                                                                : undefined
                                                        }
                                                    />

                                                    {errors.document && (
                                                        <p
                                                            id={`${fileInputId}-error`}
                                                            className="mt-2 text-sm text-destructive"
                                                        >
                                                            {errors.document}
                                                        </p>
                                                    )}
                                                </div>

                                                <p className="text-xs text-muted-foreground">
                                                    The existing approved
                                                    revision will not be
                                                    superseded by this upload.
                                                </p>

                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                >
                                                    {processing
                                                        ? 'Uploading replacement...'
                                                        : 'Upload replacement'}
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                )}
                        </section>
                    );
                })}
            </main>
        </>
    );
}
