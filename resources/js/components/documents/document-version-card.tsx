import { Form } from '@inertiajs/react';
import {
    AlertCircle,
    Check,
    FileWarning,
    RefreshCcw,
    Upload,
    X,
} from 'lucide-react';
import { useRef } from 'react';
import type { ComponentProps, RefObject } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { DocumentStatus, DocumentVersion } from '@/types/documents';

type ButtonVariant = ComponentProps<typeof Button>['variant'];

type ActionRequest = {
    action: string;
    rate_limit: string;
};

type ReplacementRequest = ActionRequest & {
    document: File | null;
};

type Props = {
    version: DocumentVersion;
};

type LifecycleActionFormProps = {
    url: string;
    versionNumber: number;
    label: string;
    processingLabel: string;
    confirmation?: string;
    variant?: ButtonVariant;
    icon: React.ReactNode;
};

/**
 * Human-readable presentation for every backend document state.
 */
const statusLabels: Record<DocumentStatus, string> = {
    uploaded: 'Uploaded',
    quarantined: 'Quarantined',
    scan_pending: 'Scan pending',
    scan_failed: 'Scan failed',
    scan_approved: 'Scan approved',
    parsing: 'Parsing',
    parse_failed: 'Parsing failed',
    parsed: 'Parsed',
    analysis_pending: 'Analysis pending',
    analyzing: 'Analyzing',
    analysis_failed: 'Analysis failed',
    needs_review: 'Needs review',
    approved: 'Approved',
    rejected: 'Rejected',
    superseded: 'Superseded',
};

/**
 * Explain what the current authoritative state means to the user.
 */
const statusDescriptions: Record<DocumentStatus, string> = {
    uploaded: 'The immutable upload record has been created.',
    quarantined: 'The file is isolated until malware scanning completes.',
    scan_pending: 'The file is waiting for malware scanning.',
    scan_failed: 'Malware scanning failed and may require recovery.',
    scan_approved: 'Scanning passed and parsing can begin.',
    parsing: 'The document content is being parsed.',
    parse_failed: 'Parsing failed and may require recovery or replacement.',
    parsed: 'Parsing completed using a legacy transition state.',
    analysis_pending: 'The parsed document is waiting for analysis.',
    analyzing: 'The document is being classified and checked.',
    analysis_failed: 'Analysis failed and may be retried.',
    needs_review: 'Analysis completed and requires a human decision.',
    approved: 'This version is part of the authoritative project context.',
    rejected: 'This version was reviewed and rejected.',
    superseded: 'A later approved version replaced this revision.',
};

/**
 * Convert a backend status to its visible label.
 */
export function documentStatusLabel(status: DocumentStatus): string {
    return statusLabels[status];
}

/**
 * Format stored byte counts without exposing storage paths.
 */
function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * Focus an action validation summary after a rejected submission.
 */
function focusErrorSummary(ref: RefObject<HTMLDivElement | null>): void {
    window.requestAnimationFrame(() => {
        ref.current?.focus();
    });
}

/**
 * Focus the page-level success announcement after an Inertia response.
 */
function focusFlashStatus(): void {
    window.requestAnimationFrame(() => {
        document.querySelector<HTMLElement>('[data-document-flash]')?.focus();
    });
}

/**
 * Normalize an Inertia error bag for rendering.
 */
function errorMessages(errors: Partial<Record<string, string>>): string[] {
    return Array.from(
        new Set(
            Object.values(errors).filter(
                (message): message is string =>
                    typeof message === 'string' && message.length > 0,
            ),
        ),
    );
}

/**
 * Display action-level validation and rate-limit messages.
 */
function ActionErrorSummary({
    errors,
    focusRef,
}: {
    errors: Partial<Record<string, string>>;
    focusRef: RefObject<HTMLDivElement | null>;
}) {
    const messages = errorMessages(errors);

    if (messages.length === 0) {
        return null;
    }

    return (
        <Alert
            ref={focusRef}
            variant="destructive"
            tabIndex={-1}
            className="mt-3"
        >
            <AlertTitle>Action could not be completed</AlertTitle>
            <AlertDescription>
                <ul className="list-disc pl-5">
                    {messages.map((message) => (
                        <li key={message}>{message}</li>
                    ))}
                </ul>
            </AlertDescription>
        </Alert>
    );
}

/**
 * Render one approve, reject, or retry command.
 */
function LifecycleActionForm({
    url,
    versionNumber,
    label,
    processingLabel,
    confirmation,
    variant,
    icon,
}: LifecycleActionFormProps) {
    const errorSummaryRef = useRef<HTMLDivElement>(null);

    return (
        <Form<ActionRequest>
            action={url}
            method="post"
            preserveScroll
            onBefore={
                confirmation ? () => window.confirm(confirmation) : undefined
            }
            onError={() => focusErrorSummary(errorSummaryRef)}
            onSuccess={focusFlashStatus}
        >
            {({ processing, errors }) => (
                <>
                    <Button
                        type="submit"
                        variant={variant}
                        disabled={processing}
                        aria-label={`${label} version ${versionNumber}`}
                    >
                        {processing ? <Spinner /> : icon}
                        {processing ? processingLabel : label}
                    </Button>

                    <ActionErrorSummary
                        errors={errors}
                        focusRef={errorSummaryRef}
                    />
                </>
            )}
        </Form>
    );
}

/**
 * Render a replacement upload for a server-approved predecessor.
 */
function ReplacementForm({
    url,
    versionNumber,
}: {
    url: string;
    versionNumber: number;
}) {
    const errorSummaryRef = useRef<HTMLDivElement>(null);
    const fileInputId = `replacement-${versionNumber}`;

    return (
        <Form<ReplacementRequest>
            action={url}
            method="post"
            resetOnSuccess={['document']}
            preserveScroll
            onError={() => focusErrorSummary(errorSummaryRef)}
            onSuccess={focusFlashStatus}
            className="space-y-4 rounded-lg border bg-muted/30 p-4"
        >
            {({ errors, processing, progress }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor={fileInputId}>
                            Replacement file for version {versionNumber}
                        </Label>

                        <Input
                            id={fileInputId}
                            name="document"
                            type="file"
                            accept=".md,.txt,text/markdown,text/plain"
                            required
                            aria-invalid={errors.document ? true : undefined}
                            aria-describedby={
                                errors.document
                                    ? `${fileInputId}-error`
                                    : `${fileInputId}-help`
                            }
                        />

                        <p
                            id={`${fileInputId}-help`}
                            className="text-xs text-muted-foreground"
                        >
                            The approved predecessor remains authoritative until
                            this new version completes review and is approved.
                        </p>

                        {errors.document && (
                            <p
                                id={`${fileInputId}-error`}
                                className="text-sm text-destructive"
                            >
                                {errors.document}
                            </p>
                        )}
                    </div>

                    {progress && (
                        <div className="space-y-2" aria-live="polite">
                            <div className="flex justify-between text-sm">
                                <span>Uploading replacement</span>
                                <span>{progress.percentage}%</span>
                            </div>

                            <progress
                                aria-label="Replacement upload progress"
                                className="h-2 w-full"
                                max={100}
                                value={progress.percentage}
                            />
                        </div>
                    )}

                    <Button
                        type="submit"
                        variant="outline"
                        disabled={processing}
                    >
                        {processing ? <Spinner /> : <Upload aria-hidden />}
                        {processing
                            ? 'Uploading replacement...'
                            : 'Upload replacement'}
                    </Button>

                    <ActionErrorSummary
                        errors={errors}
                        focusRef={errorSummaryRef}
                    />
                </>
            )}
        </Form>
    );
}

/**
 * Render one immutable document version and its server-issued actions.
 */
export default function DocumentVersionCard({ version }: Props) {
    const hasActions = Object.values(version.actions).some(
        (url) => url !== null,
    );

    return (
        <article
            aria-labelledby={`document-version-${version.id}`}
            className="space-y-5 rounded-xl border bg-card p-5 shadow-sm"
        >
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2
                        id={`document-version-${version.id}`}
                        className="font-semibold"
                    >
                        Version {version.version}
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {version.originalFilename} ·{' '}
                        {formatBytes(version.byteSize)}
                    </p>
                </div>

                <span className="rounded-full border px-2.5 py-1 text-xs font-medium">
                    {documentStatusLabel(version.status)}
                </span>
            </header>

            <p role="status" className="text-sm text-muted-foreground">
                {statusDescriptions[version.status]}
            </p>

            {version.failure && (
                <Alert variant="destructive">
                    <AlertCircle aria-hidden />
                    <AlertTitle>
                        {documentStatusLabel(version.status)}
                    </AlertTitle>
                    <AlertDescription>
                        {version.failure.message ??
                            'The processing stage did not complete.'}

                        {version.failure.code && (
                            <p className="mt-2">
                                Error code: <code>{version.failure.code}</code>
                            </p>
                        )}
                    </AlertDescription>
                </Alert>
            )}

            {version.flags.length > 0 && (
                <Alert variant="destructive">
                    <FileWarning aria-hidden />
                    <AlertTitle>Safety review required</AlertTitle>
                    <AlertDescription>
                        <ul className="list-disc pl-5">
                            {version.flags.map((flag) => (
                                <li key={flag}>{flag.replaceAll('_', ' ')}</li>
                            ))}
                        </ul>
                    </AlertDescription>
                </Alert>
            )}

            <dl className="grid gap-4 text-sm md:grid-cols-2">
                <div>
                    <dt className="text-muted-foreground">Classification</dt>
                    <dd className="mt-1 font-medium">
                        {version.classification}
                    </dd>
                </div>

                <div>
                    <dt className="text-muted-foreground">Media type</dt>
                    <dd className="mt-1 font-medium">{version.mediaType}</dd>
                </div>

                <div>
                    <dt className="text-muted-foreground">Parser</dt>
                    <dd className="mt-1 font-medium">
                        {version.parserName && version.parserVersion
                            ? `${version.parserName}@${version.parserVersion}`
                            : 'Pending'}
                    </dd>
                </div>

                <div>
                    <dt className="text-muted-foreground">Analyzer</dt>
                    <dd className="mt-1 font-medium">
                        {version.analyzerName && version.analyzerVersion
                            ? `${version.analyzerName}@${version.analyzerVersion}`
                            : 'Pending'}
                    </dd>
                </div>

                <div className="md:col-span-2">
                    <dt className="text-muted-foreground">Checksum</dt>
                    <dd className="mt-1 break-all">
                        <code>{version.checksum}</code>
                    </dd>
                </div>
            </dl>

            {version.summary && (
                <section>
                    <h3 className="text-sm font-semibold">Analysis summary</h3>
                    <p className="mt-2 text-sm leading-6 text-muted-foreground">
                        {version.summary}
                    </p>
                </section>
            )}

            {version.conflicts.length > 0 && (
                <section>
                    <h3 className="text-sm font-semibold">Conflicts</h3>
                    <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                        {version.conflicts.map((conflict) => (
                            <li key={conflict}>{conflict}</li>
                        ))}
                    </ul>
                </section>
            )}

            {version.gaps.length > 0 && (
                <section>
                    <h3 className="text-sm font-semibold">
                        Missing information
                    </h3>
                    <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                        {version.gaps.map((gap) => (
                            <li key={gap}>{gap}</li>
                        ))}
                    </ul>
                </section>
            )}

            {hasActions && (
                <section
                    aria-labelledby={`version-${version.id}-actions`}
                    className="space-y-4 border-t pt-5"
                >
                    <h3
                        id={`version-${version.id}-actions`}
                        className="text-sm font-semibold"
                    >
                        Available actions
                    </h3>

                    <div className="flex flex-wrap gap-3">
                        {version.actions.approve && (
                            <LifecycleActionForm
                                url={version.actions.approve}
                                versionNumber={version.version}
                                label="Approve"
                                processingLabel="Approving..."
                                confirmation={`Approve version ${version.version} as authoritative project context?`}
                                icon={<Check aria-hidden />}
                            />
                        )}

                        {version.actions.reject && (
                            <LifecycleActionForm
                                url={version.actions.reject}
                                versionNumber={version.version}
                                label="Reject"
                                processingLabel="Rejecting..."
                                confirmation={`Reject version ${version.version}?`}
                                variant="outline"
                                icon={<X aria-hidden />}
                            />
                        )}

                        {version.actions.retry && (
                            <LifecycleActionForm
                                url={version.actions.retry}
                                versionNumber={version.version}
                                label="Retry processing"
                                processingLabel="Retrying..."
                                variant="outline"
                                icon={<RefreshCcw aria-hidden />}
                            />
                        )}
                    </div>

                    {version.actions.replacement && (
                        <ReplacementForm
                            url={version.actions.replacement}
                            versionNumber={version.version}
                        />
                    )}
                </section>
            )}
        </article>
    );
}
