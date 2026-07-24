import { Head, Link } from '@inertiajs/react';

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
};

type Props = {
    document: {
        title: string;
        versions: DocumentVersion[];
    };
    urls: {
        index: string;
    };
};

export default function DocumentShow({ document, urls }: Props) {
    return (
        <>
            <Head title={document.title} />

            <main className="space-y-6 p-6">
                <Link href={urls.index}>Back to documents</Link>

                <h1 className="text-2xl font-semibold">
                    {document.title}
                </h1>

                {document.versions.map((version) => (
                    <section
                        key={version.id}
                        className="rounded-xl border p-4"
                    >
                        <h2 className="font-semibold">
                            Version {version.version}
                        </h2>

                        <dl className="mt-3 grid gap-2 text-sm">
                            <div>Status: {version.status}</div>

                            <div>
                                Classification: {version.classification}
                            </div>

                            <div>
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
                                    ? new Date(version.analysisCompletedAt).toLocaleString()
                                    : 'Pending'}
                            </div>

                            <div>
                                Notes: {version.notes ?? 'None'}
                            </div>

                            <div>
                                Safety flags:{' '}
                                {version.flags.length > 0
                                    ? version.flags.join(', ')
                                    : 'None'}
                            </div>
                        </dl>
                    </section>
                ))}
            </main>
        </>
    );
}
