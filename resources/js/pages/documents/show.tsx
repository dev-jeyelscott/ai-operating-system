import { Head, Link } from '@inertiajs/react';
import DocumentVersionCard from '@/components/documents/document-version-card';
import { Button } from '@/components/ui/button';
import type { DocumentFlash, ProjectDocument } from '@/types/documents';

type Props = {
    document: ProjectDocument;
    urls: {
        index: string;
    };
    flash: DocumentFlash;
};

/**
 * Translate lifecycle flash codes into accessible confirmation messages.
 */
function flashMessage(status: string): string {
    const messages: Record<string, string> = {
        'document-uploaded': 'Document uploaded. Processing has been queued.',
        'document-version-approved': 'Document version approved.',
        'document-version-rejected': 'Document version rejected.',
        'document-replacement-uploaded':
            'Replacement version uploaded. The previous approved version remains authoritative until review completes.',
        'document-processing-retried':
            'Document processing was queued for retry.',
    };

    return messages[status] ?? status.replaceAll('-', ' ').replaceAll('_', ' ');
}

/**
 * Display every immutable revision and its server-authorized actions.
 */
export default function DocumentShow({ document, urls, flash }: Props) {
    return (
        <>
            <Head title={document.title} />

            <main className="space-y-6 p-4 md:p-6">
                <Button asChild variant="ghost" size="sm">
                    <Link href={urls.index}>Back to documents</Link>
                </Button>

                <header>
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="text-2xl font-semibold">
                            {document.title}
                        </h1>

                        {document.documentClass && (
                            <span className="rounded-full border px-2.5 py-1 text-xs font-medium">
                                {document.documentClass}
                            </span>
                        )}
                    </div>

                    <p className="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground">
                        Versions are immutable. Replacement uploads create a new
                        quarantined revision. An approved predecessor remains
                        authoritative until its replacement finishes analysis
                        and receives explicit approval.
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

                {document.versions.length === 0 ? (
                    <section className="rounded-xl border border-dashed p-8 text-center">
                        <h2 className="font-medium">No document versions</h2>
                        <p className="mt-2 text-sm text-muted-foreground">
                            This document does not currently contain an uploaded
                            revision.
                        </p>
                    </section>
                ) : (
                    <section
                        aria-label="Document versions"
                        className="space-y-5"
                    >
                        {document.versions.map((version) => (
                            <DocumentVersionCard
                                key={version.id}
                                version={version}
                            />
                        ))}
                    </section>
                )}
            </main>
        </>
    );
}
