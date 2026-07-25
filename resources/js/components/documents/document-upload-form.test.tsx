import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { vi } from 'vitest';
import DocumentUploadForm from './document-upload-form';

vi.mock('@inertiajs/react', () => ({
    Form: ({
        children,
    }: {
        children: (props: {
            errors: Record<string, string>;
            processing: boolean;
            progress: {
                percentage: number;
            };
            hasErrors: boolean;
        }) => ReactNode;
    }) => (
        <form>
            {children({
                errors: {
                    rate_limit:
                        'Too many requests. Please retry in 60 seconds.',
                },
                processing: true,
                progress: {
                    percentage: 42,
                },
                hasErrors: true,
            })}
        </form>
    ),
}));

test('upload progress and rate limit errors are accessible', () => {
    render(<DocumentUploadForm storeUrl="/documents" />);

    expect(
        screen.getByText('Too many requests. Please retry in 60 seconds.'),
    ).toBeInTheDocument();

    expect(
        screen.getByRole('progressbar', {
            name: 'Upload progress',
        }),
    ).toHaveAttribute('value', '42');

    expect(
        screen.getByRole('button', {
            name: 'Uploading document...',
        }),
    ).toBeDisabled();
});
