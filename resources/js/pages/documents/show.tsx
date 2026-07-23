import { Head, Link } from '@inertiajs/react';

type DocumentVersion = {
    id: number;
    version: number;
    status: string;
    classification: string;
    checksum: string;
    parserVersion: string | null;
    notes: string | null;
};

type Props = {
    document: { title: string; versions: DocumentVersion[] };
    urls: { index: string };
};

export default function DocumentShow({ document, urls }: Props) {
    return (
        <>
            <Head title={document.title} />

            <main className="space-y-6 p-6">
                <Link href={urls.index}>Back to documents</Link>
                <h1 className="text-2xl font-semibold">{document.title}</h1>

                {document.versions.map((version) => (
                    <section key={version.id} className="rounded-xl border p-4">
                        <h2 className="font-semibold">Version {version.version}</h2>
                        <dl className="mt-3 grid gap-2 text-sm">
                            <div>Status: {version.status}</div>
                            <div>Classification: {version.classification}</div>
                            <div>
                                Checksum: <code>{version.checksum}</code>
                            </div>
                            <div>Parser: {version.parserVersion ?? 'Pending'}</div>
                            <div>Notes: {version.notes ?? 'None'}</div>
                        </dl>
                    </section>
                ))}
            </main>
        </>
    );
}
