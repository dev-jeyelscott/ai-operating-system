import { Head, Link } from '@inertiajs/react';
import DocumentUploadForm from '@/components/documents/document-upload-form';
import { documentStatusLabel } from '@/components/documents/document-version-card';
import { Button } from '@/components/ui/button';
import type { DocumentFlash, ProjectDocument } from '@/types/documents';

type Props = {
    project: {
        name: string;
    };
    documents: ProjectDocument[];
    permissions: {
        upload: boolean;
    };
    urls: {
        project: string;
        store: string | null;
    };
    flash: DocumentFlash;
};

/**
 * Translate backend flash codes into concise user-facing confirmations.
 */
function flashMessage(status: string): string {
    const messages: Record<string, string> = {
        'document-uploaded': 'Document uploaded. Processing has been queued.',
        'document-version-approved': 'Document version approved.',
        'document-version-rejected': 'Document version rejected.',
        'document-replacement-uploaded': 'Replacement version uploaded.',
        'document-processing-retried':
            'Document processing was queued for retry.',
    };

    return messages[status] ?? status.replaceAll('-', ' ').replaceAll('_', ' ');
}

/**
 * Render the document center inventory and authorized upload workflow.
 */
export default function DocumentsIndex({
    project,
    documents,
    permissions,
    urls,
    flash,
}: Props) {
    return (
        <>
            <Head title={`${project.name} documents`} />

            <main className="space-y-6 p-4 md:p-6">
                <Button asChild variant="ghost" size="sm">
                    <Link href={urls.project}>Back to project</Link>
                </Button>

                <header>
                    <h1 className="text-2xl font-semibold">Document center</h1>
                    <p className="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground">
                        Upload, inspect, review, replace, and recover the
                        project documents used to build authoritative context.
                    </p>
                </header>

                {flash.status && (
                    <div
                        data-document-flash
                        role="status"
                        aria-live="polite"
                        tabIndex={-1}
                        className="rounded-lg border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flashMessage(flash.status)}
                    </div>
                )}

                {permissions.upload && urls.store ? (
                    <DocumentUploadForm storeUrl={urls.store} />
                ) : (
                    <section className="rounded-xl border bg-card p-5">
                        <h2 className="font-semibold">Read-only access</h2>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Your organization role can inspect documents but
                            cannot upload or change document versions.
                        </p>
                    </section>
                )}

                <section
                    aria-labelledby="document-inventory-heading"
                    className="space-y-4"
                >
                    <h2
                        id="document-inventory-heading"
                        className="text-lg font-semibold"
                    >
                        Project documents
                    </h2>

                    {documents.length === 0 ? (
                        <div className="rounded-xl border border-dashed p-8 text-center">
                            <h3 className="font-medium">
                                No documents uploaded
                            </h3>
                            <p className="mt-2 text-sm text-muted-foreground">
                                Upload the first approved project source
                                document to begin the Phase 3 workflow.
                            </p>
                        </div>
                    ) : (
                        <div className="grid gap-4">
                            {documents.map((document) => {
                                const latestVersion = document.versions[0];

                                return (
                                    <article
                                        key={document.id}
                                        className="rounded-xl border bg-card p-5 shadow-sm"
                                    >
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <Link
                                                    href={document.url ?? '#'}
                                                    className="font-semibold underline-offset-4 hover:underline"
                                                >
                                                    {document.title}
                                                </Link>

                                                <p className="mt-1 text-sm text-muted-foreground">
                                                    {document.documentClass
                                                        ? `Class: ${document.documentClass}`
                                                        : 'No document class assigned'}
                                                </p>
                                            </div>

                                            {latestVersion && (
                                                <span className="rounded-full border px-2.5 py-1 text-xs font-medium">
                                                    {documentStatusLabel(
                                                        latestVersion.status,
                                                    )}
                                                </span>
                                            )}
                                        </div>

                                        {latestVersion ? (
                                            <p className="mt-4 text-sm text-muted-foreground">
                                                Latest version:{' '}
                                                {latestVersion.version} ·{' '}
                                                {latestVersion.originalFilename}
                                            </p>
                                        ) : (
                                            <p className="mt-4 text-sm text-muted-foreground">
                                                No versions are available.
                                            </p>
                                        )}
                                    </article>
                                );
                            })}
                        </div>
                    )}
                </section>
            </main>
        </>
    );
}
