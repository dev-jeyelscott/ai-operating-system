import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { vi } from 'vitest';
import type { DocumentVersion } from '@/types/documents';
import DocumentVersionCard from './document-version-card';

vi.mock('@inertiajs/react', () => ({
    Form: ({
        children,
        onBefore,
    }: {
        children: (props: {
            errors: Record<string, string>;
            processing: boolean;
            progress: null;
        }) => ReactNode;
        onBefore?: () => boolean;
    }) => (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                onBefore?.();
            }}
        >
            {children({
                errors: {},
                processing: false,
                progress: null,
            })}
        </form>
    ),
}));

/**
 * Create a complete document-version fixture with focused overrides.
 */
function version(overrides: Partial<DocumentVersion> = {}): DocumentVersion {
    return {
        id: 1,
        version: 1,
        originalFilename: 'architecture.md',
        mediaType: 'text/markdown',
        byteSize: 1024,
        status: 'needs_review',
        classification: 'architecture',
        checksum: 'a'.repeat(64),
        parserName: 'plain-text-mvp',
        parserVersion: '1.0.0',
        analyzerName: 'deterministic-document-analyzer',
        analyzerVersion: '1.0.0',
        analysisSeed: 42,
        analysisCompletedAt: '2026-07-25T00:00:00+00:00',
        summary: 'Architecture analysis completed.',
        conflicts: [],
        gaps: [],
        flags: [],
        failure: null,
        actions: {
            approve: '/approve',
            reject: '/reject',
            replacement: null,
            retry: null,
        },
        ...overrides,
    };
}

test('review versions expose only server-issued review actions', () => {
    render(<DocumentVersionCard version={version()} />);

    expect(
        screen.getByRole('button', {
            name: 'Approve version 1',
        }),
    ).toBeInTheDocument();

    expect(
        screen.getByRole('button', {
            name: 'Reject version 1',
        }),
    ).toBeInTheDocument();

    expect(
        screen.queryByRole('button', {
            name: /retry processing/i,
        }),
    ).not.toBeInTheDocument();

    expect(
        screen.queryByLabelText(/replacement file/i),
    ).not.toBeInTheDocument();
});

test('approved versions expose only the replacement capability', () => {
    render(
        <DocumentVersionCard
            version={version({
                status: 'approved',
                actions: {
                    approve: null,
                    reject: null,
                    replacement: '/replacement',
                    retry: null,
                },
            })}
        />,
    );

    expect(
        screen.getByLabelText('Replacement file for version 1'),
    ).toBeInTheDocument();

    expect(
        screen.queryByRole('button', {
            name: 'Approve version 1',
        }),
    ).not.toBeInTheDocument();
});

test('failed versions expose failure information and retry', () => {
    render(
        <DocumentVersionCard
            version={version({
                status: 'analysis_failed',
                failure: {
                    code: 'analysis_failed',
                    message: 'Analysis could not be completed.',
                },
                actions: {
                    approve: null,
                    reject: null,
                    replacement: null,
                    retry: '/retry',
                },
            })}
        />,
    );

    expect(
        screen.getByText('Analysis could not be completed.'),
    ).toBeInTheDocument();

    expect(
        screen.getByRole('button', {
            name: 'Retry processing version 1',
        }),
    ).toBeInTheDocument();
});

test('read only payloads render no actionable controls', () => {
    render(
        <DocumentVersionCard
            version={version({
                actions: {
                    approve: null,
                    reject: null,
                    replacement: null,
                    retry: null,
                },
            })}
        />,
    );

    expect(screen.queryByText('Available actions')).not.toBeInTheDocument();
});

test('keyboard activation requests confirmation before approval', async () => {
    const user = userEvent.setup();
    const confirmation = vi.spyOn(window, 'confirm').mockReturnValue(true);

    render(<DocumentVersionCard version={version()} />);

    const approve = screen.getByRole('button', {
        name: 'Approve version 1',
    });

    approve.focus();
    await user.keyboard('{Enter}');

    expect(confirmation).toHaveBeenCalledWith(
        'Approve version 1 as authoritative project context?',
    );
});
