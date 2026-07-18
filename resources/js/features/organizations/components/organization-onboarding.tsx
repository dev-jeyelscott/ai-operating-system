import { Form } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { store } from '@/routes/organizations';

/**
 * Guides a new user through creating their first organization.
 */
export function OrganizationOnboarding() {
    return (
        <div className="flex min-h-[calc(100vh-8rem)] items-center justify-center p-4">
            <div className="w-full max-w-xl rounded-xl border border-sidebar-border/70 bg-card p-6 shadow-sm md:p-8 dark:border-sidebar-border">
                <div className="mb-6 flex size-12 items-center justify-center rounded-xl bg-primary/10">
                    <Building2
                        className="size-6 text-primary"
                        aria-hidden="true"
                    />
                </div>

                <div className="space-y-2">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Create your organization
                    </h1>

                    <p className="text-sm text-muted-foreground">
                        Organizations keep projects, documents, workflows, and
                        agent activity securely separated.
                    </p>
                </div>

                <Form
                    {...store.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    resetOnSuccess
                    className="mt-8 space-y-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="organization-name">
                                    Organization name
                                </Label>

                                <Input
                                    id="organization-name"
                                    name="name"
                                    type="text"
                                    required
                                    minLength={2}
                                    maxLength={120}
                                    autoComplete="organization"
                                    autoFocus
                                    placeholder="Acme Engineering"
                                    aria-describedby={
                                        errors.name
                                            ? 'organization-name-error'
                                            : undefined
                                    }
                                />

                                <InputError
                                    id="organization-name-error"
                                    message={errors.name}
                                />
                            </div>

                            <Button
                                type="submit"
                                disabled={processing}
                                className="w-full sm:w-auto"
                            >
                                {processing
                                    ? 'Creating organization...'
                                    : 'Create organization'}
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </div>
    );
}
