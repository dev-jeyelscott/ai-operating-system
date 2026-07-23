import { Head, Link } from '@inertiajs/react';

type DocumentVersion = {
    id: number;
    version: number;
    status: string;
    classification: string;
};

type Document = {
    id: number;
    title: string;
    url: string;
    versions: DocumentVersion[];
};

type Props = {
    project: { name: string };
    documents: Document[];
    urls: { project: string };
};

export default function DocumentsIndex({ project, documents, urls }: Props) {
    return (
        <>
            <Head title={`${project.name} documents`} />

            <main className="space-y-6 p-6">
                <Link href={urls.project}>Back to project</Link>
                <h1 className="text-2xl font-semibold">Document center</h1>

                <div className="grid gap-4">
                    {documents.map((document) => (
                        <article key={document.id} className="rounded-xl border p-4">
                            <Link href={document.url} className="font-semibold">
                                {document.title}
                            </Link>

                            {document.versions.map((version) => (
                                <p key={version.id} className="mt-2 text-sm text-muted-foreground">
                                    v{version.version} · {version.status} · {version.classification}
                                </p>
                            ))}
                        </article>
                    ))}
                </div>
            </main>
        </>
    );
}
