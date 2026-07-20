import { Link } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2 } from 'lucide-react';
import type { ProjectConfigurationValidation } from '@/types';

type Props = {
    title: string;
    validation: ProjectConfigurationValidation;
    setupUrl: string;
    setupStepUrls: Record<string, string>;
    completeMessage?: string;
    incompleteMessage?: string;
};

/**
 * Render deterministic validation results and actionable remediation links.
 */
export function ProjectReadinessPanel({
    title,
    validation,
    setupUrl,
    setupStepUrls,
    completeMessage = 'All required project configuration is complete.',
    incompleteMessage = 'Resolve the following configuration requirements.',
}: Props) {
    return (
        <section
            className="rounded-xl border bg-card p-6 shadow-sm"
            aria-labelledby="project-readiness-title"
        >
            <div className="flex items-start gap-3">
                {validation.complete ? (
                    <CheckCircle2
                        className="mt-0.5 size-5 shrink-0"
                        aria-hidden="true"
                    />
                ) : (
                    <AlertTriangle
                        className="mt-0.5 size-5 shrink-0"
                        aria-hidden="true"
                    />
                )}

                <div className="min-w-0 flex-1">
                    <h2
                        id="project-readiness-title"
                        className="font-semibold"
                    >
                        {title}
                    </h2>

                    <p className="mt-1 text-sm text-muted-foreground">
                        {validation.complete
                            ? completeMessage
                            : incompleteMessage}
                    </p>
                </div>
            </div>

            {!validation.complete &&
                validation.missingConfiguration.length > 0 && (
                    <ul className="mt-5 space-y-3">
                        {validation.missingConfiguration.map((issue) => (
                            <li
                                key={issue.key}
                                className="rounded-lg border bg-muted/20 p-4"
                            >
                                <p className="text-sm font-medium">
                                    {issue.message}
                                </p>

                                <p className="mt-1 text-sm text-muted-foreground">
                                    {issue.remediation}
                                </p>

                                <Link
                                    href={
                                        setupStepUrls[issue.step] ?? setupUrl
                                    }
                                    className="mt-3 inline-flex text-sm font-medium underline underline-offset-4"
                                >
                                    Open configuration step
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
        </section>
    );
}