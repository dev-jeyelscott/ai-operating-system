import { Form } from '@inertiajs/react';
import { useRef, type RefObject } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type UploadDocumentForm = {
    title: string;
    document_class: string;
    document: File | null;
    rate_limit: string;
};

type Props = {
    storeUrl: string;
};

/**
 * Move keyboard focus to the validation summary after a failed submission.
 */
function focusErrorSummary(ref: RefObject<HTMLDivElement | null>): void {
    window.requestAnimationFrame(() => {
        ref.current?.focus();
    });
}

/**
 * Move focus to the server-confirmed status message after success.
 */
function focusFlashStatus(): void {
    window.requestAnimationFrame(() => {
        const element = document.querySelector<HTMLElement>(
            '[data-document-flash]',
        );

        element?.focus();
    });
}

/**
 * Return every non-empty form error exactly once.
 */
function formErrorMessages(
    errors: Partial<Record<keyof UploadDocumentForm, string>>,
): string[] {
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
 * Render an authorized multipart document upload with accessible progress.
 */
export default function DocumentUploadForm({ storeUrl }: Props) {
    const errorSummaryRef = useRef<HTMLDivElement>(null);

    return (
        <section
            aria-labelledby="document-upload-heading"
            className="rounded-xl border bg-card p-5 shadow-sm"
        >
            <h2 id="document-upload-heading" className="text-lg font-semibold">
                Upload document
            </h2>

            <p className="mt-1 text-sm text-muted-foreground">
                Supported formats are Markdown and plain text, up to 20 MB.
                Every upload creates an immutable version and enters the
                quarantine and processing workflow.
            </p>

            <Form<UploadDocumentForm>
                action={storeUrl}
                method="post"
                resetOnSuccess={['title', 'document_class', 'document']}
                preserveScroll
                onError={() => focusErrorSummary(errorSummaryRef)}
                onSuccess={focusFlashStatus}
                className="mt-5 space-y-5"
            >
                {({ errors, processing, progress, hasErrors }) => {
                    const messages = formErrorMessages(errors);

                    return (
                        <>
                            {hasErrors && messages.length > 0 && (
                                <Alert
                                    ref={errorSummaryRef}
                                    variant="destructive"
                                    tabIndex={-1}
                                >
                                    <AlertTitle>
                                        Upload could not be completed
                                    </AlertTitle>
                                    <AlertDescription>
                                        <ul className="list-disc pl-5">
                                            {messages.map((message) => (
                                                <li key={message}>{message}</li>
                                            ))}
                                        </ul>
                                    </AlertDescription>
                                </Alert>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="document-title">
                                    Document title
                                </Label>
                                <Input
                                    id="document-title"
                                    name="title"
                                    required
                                    maxLength={191}
                                    autoComplete="off"
                                    aria-invalid={
                                        errors.title ? true : undefined
                                    }
                                    aria-describedby={
                                        errors.title
                                            ? 'document-title-error'
                                            : undefined
                                    }
                                />

                                {errors.title && (
                                    <p
                                        id="document-title-error"
                                        className="text-sm text-destructive"
                                    >
                                        {errors.title}
                                    </p>
                                )}
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="document-class">
                                    Document class
                                </Label>
                                <Input
                                    id="document-class"
                                    name="document_class"
                                    placeholder="architecture"
                                    maxLength={100}
                                    pattern="[a-z][a-z0-9_]*"
                                    autoComplete="off"
                                    aria-invalid={
                                        errors.document_class ? true : undefined
                                    }
                                    aria-describedby="document-class-help document-class-error"
                                />

                                <p
                                    id="document-class-help"
                                    className="text-xs text-muted-foreground"
                                >
                                    Optional lowercase identifier such as
                                    architecture, requirements, security, or
                                    testing_strategy.
                                </p>

                                {errors.document_class && (
                                    <p
                                        id="document-class-error"
                                        className="text-sm text-destructive"
                                    >
                                        {errors.document_class}
                                    </p>
                                )}
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="document-file">
                                    Document file
                                </Label>
                                <Input
                                    id="document-file"
                                    name="document"
                                    type="file"
                                    accept=".md,.txt,text/markdown,text/plain"
                                    required
                                    aria-invalid={
                                        errors.document ? true : undefined
                                    }
                                    aria-describedby={
                                        errors.document
                                            ? 'document-file-error'
                                            : undefined
                                    }
                                />

                                {errors.document && (
                                    <p
                                        id="document-file-error"
                                        className="text-sm text-destructive"
                                    >
                                        {errors.document}
                                    </p>
                                )}
                            </div>

                            {progress && (
                                <div className="space-y-2" aria-live="polite">
                                    <div className="flex justify-between text-sm">
                                        <span>Uploading document</span>
                                        <span>{progress.percentage}%</span>
                                    </div>

                                    <progress
                                        aria-label="Upload progress"
                                        className="h-2 w-full"
                                        max={100}
                                        value={progress.percentage}
                                    />
                                </div>
                            )}

                            <Button type="submit" disabled={processing}>
                                {processing && <Spinner />}
                                {processing
                                    ? 'Uploading document...'
                                    : 'Upload document'}
                            </Button>
                        </>
                    );
                }}
            </Form>
        </section>
    );
}
