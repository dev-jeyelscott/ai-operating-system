import { Check } from 'lucide-react';
import type { ProjectSetupStep } from '@/types';

type Props = {
    steps: ProjectSetupStep[];
    activeStep: string;
};

/**
 * Render keyboard-accessible project setup navigation.
 */
export function ProjectSetupStepper({ steps, activeStep }: Props) {
    return (
        <nav aria-label="Project setup progress">
            <ol className="grid gap-3 md:grid-cols-5">
                {steps.map((step, index) => {
                    const isActive = step.value === activeStep;

                    const content = (
                        <>
                            <span
                                className="flex size-8 shrink-0 items-center justify-center rounded-full border text-sm font-medium"
                                aria-hidden="true"
                            >
                                {step.completed ? (
                                    <Check className="size-4" />
                                ) : (
                                    index + 1
                                )}
                            </span>

                            <span className="min-w-0">
                                <span className="block truncate text-sm font-medium">
                                    {step.label}
                                </span>

                                <span className="sr-only">
                                    {step.completed
                                        ? 'Completed'
                                        : isActive
                                          ? 'Current step'
                                          : 'Not completed'}
                                </span>
                            </span>
                        </>
                    );

                    return (
                        <li key={step.value}>
                            {step.canVisit ? (
                                <a
                                    href={step.href}
                                    aria-current={isActive ? 'step' : undefined}
                                    className="flex min-h-16 items-center gap-3 rounded-lg border bg-card px-3 py-2 transition-colors hover:bg-muted/50 aria-[current=step]:border-primary aria-[current=step]:bg-primary/5"
                                >
                                    {content}
                                </a>
                            ) : (
                                <div
                                    aria-disabled="true"
                                    className="flex min-h-16 items-center gap-3 rounded-lg border bg-muted/30 px-3 py-2 text-muted-foreground opacity-70"
                                >
                                    {content}
                                </div>
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
