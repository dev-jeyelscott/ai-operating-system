import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    LIMITED_OFFICE_RENDERER_CAPABILITY,
    SUPPORTED_OFFICE_RENDERER_CAPABILITY,
    UNAVAILABLE_OFFICE_RENDERER_CAPABILITY,
} from '@/features/office/webgl-capability';
import type { OfficeRendererCapability } from '@/features/office/webgl-capability';
import { officeProjectionFixture } from '@/tests/fixtures/office-projection';

const officeCanvasMock = vi.hoisted(() => ({
    shouldThrow: false,
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
}));

vi.mock('./office-canvas', () => ({
    default: ({ qualityPreset }: { qualityPreset: string }) => {
        if (officeCanvasMock.shouldThrow) {
            throw new Error('Simulated renderer initialization failure.');
        }

        return (
            <div
                data-testid="mock-office-canvas"
                data-quality-preset={qualityPreset}
            >
                Mock office Canvas
            </div>
        );
    },
}));

import { OfficeShell } from './office-shell';

/**
 * Render the shell with a deterministic capability result.
 */
function renderOfficeShell(
    capability: OfficeRendererCapability = SUPPORTED_OFFICE_RENDERER_CAPABILITY,
) {
    return render(
        <OfficeShell
            projection={officeProjectionFixture()}
            operationsUrl="/operations"
            rendererCapabilityDetector={() => capability}
        />,
    );
}

describe('OfficeShell', () => {
    beforeEach(() => {
        officeCanvasMock.shouldThrow = false;
    });

    it('does not render the Canvas module before the user requests it', async () => {
        renderOfficeShell();

        expect(
            screen.queryByTestId('mock-office-canvas'),
        ).not.toBeInTheDocument();

        expect(
            await screen.findByRole('button', {
                name: /load 3d office/i,
            }),
        ).toBeInTheDocument();

        expect(
            screen.getByRole('link', {
                name: /open accessible dashboard/i,
            }),
        ).toHaveAttribute('href', '/operations');
    });

    it('renders accessible quality controls before WebGL loads', async () => {
        renderOfficeShell();

        await screen.findByRole('button', {
            name: /load 3d office/i,
        });

        expect(
            screen.getByRole('button', {
                name: 'Low',
            }),
        ).toHaveAttribute('aria-pressed', 'false');

        expect(
            screen.getByRole('button', {
                name: 'Balanced',
            }),
        ).toHaveAttribute('aria-pressed', 'true');

        expect(
            screen.getByRole('button', {
                name: 'High',
            }),
        ).toHaveAttribute('aria-pressed', 'false');
    });

    it('renders the lazy Canvas with balanced quality by default', async () => {
        const user = userEvent.setup();

        renderOfficeShell();

        await user.click(
            await screen.findByRole('button', {
                name: /load 3d office/i,
            }),
        );

        expect(await screen.findByTestId('mock-office-canvas')).toHaveAttribute(
            'data-quality-preset',
            'balanced',
        );
    });

    it('passes the selected rendering quality to the loaded Canvas', async () => {
        const user = userEvent.setup();

        renderOfficeShell();

        await user.click(
            await screen.findByRole('button', {
                name: /load 3d office/i,
            }),
        );

        await user.click(
            screen.getByRole('button', {
                name: 'Low',
            }),
        );

        expect(await screen.findByTestId('mock-office-canvas')).toHaveAttribute(
            'data-quality-preset',
            'low',
        );

        expect(
            screen.getByRole('button', {
                name: 'Low',
            }),
        ).toHaveAttribute('aria-pressed', 'true');

        expect(
            screen.getByText(/permits browser-reported performance caveats/i),
        ).toBeInTheDocument();
    });

    it('can switch from low to high without changing projection truth', async () => {
        const user = userEvent.setup();
        const projection = officeProjectionFixture();

        render(
            <OfficeShell
                projection={projection}
                operationsUrl="/operations"
                rendererCapabilityDetector={() =>
                    SUPPORTED_OFFICE_RENDERER_CAPABILITY
                }
            />,
        );

        await user.click(
            await screen.findByRole('button', {
                name: 'Low',
            }),
        );

        await user.click(
            screen.getByRole('button', {
                name: /load 3d office/i,
            }),
        );

        expect(await screen.findByTestId('mock-office-canvas')).toHaveAttribute(
            'data-quality-preset',
            'low',
        );

        await user.click(
            screen.getByRole('button', {
                name: 'High',
            }),
        );

        expect(await screen.findByTestId('mock-office-canvas')).toHaveAttribute(
            'data-quality-preset',
            'high',
        );

        expect(projection.agents[0].officeState).toBe('implementing');
        expect(projection.rooms[2].state).toBe('working');
    });

    it('keeps simulation status visible before WebGL loads', () => {
        renderOfficeShell();

        expect(
            screen.getByText('Simulation remains unverified'),
        ).toBeInTheDocument();
    });

    it('automatically selects low quality for a limited renderer', async () => {
        renderOfficeShell(LIMITED_OFFICE_RENDERER_CAPABILITY);

        expect(
            await screen.findByText('Low-capability mode enabled'),
        ).toBeInTheDocument();

        expect(
            screen.getByRole('button', {
                name: 'Low',
            }),
        ).toHaveAttribute('aria-pressed', 'true');

        expect(
            screen.getByRole('button', {
                name: /load 3d office/i,
            }),
        ).toBeInTheDocument();
    });

    it('keeps the dashboard and projected DOM state available without WebGL', async () => {
        renderOfficeShell(UNAVAILABLE_OFFICE_RENDERER_CAPABILITY);

        expect(
            await screen.findByRole('heading', {
                name: '3D office unavailable',
            }),
        ).toBeInTheDocument();

        expect(
            screen.queryByRole('button', {
                name: /load 3d office/i,
            }),
        ).not.toBeInTheDocument();

        expect(
            screen.getByRole('link', {
                name: /continue in operational dashboard/i,
            }),
        ).toHaveAttribute('href', '/operations');

        expect(
            screen.getByRole('button', {
                name: 'Low',
            }),
        ).toBeDisabled();

        expect(screen.getByText('Backend Engineer')).toBeInTheDocument();

        expect(
            screen.getByRole('navigation', {
                name: /office room navigation/i,
            }),
        ).toBeInTheDocument();
    });

    it('replaces only the failed renderer and retries with low quality', async () => {
        const user = userEvent.setup();

        vi.spyOn(console, 'error').mockImplementation(() => undefined);

        officeCanvasMock.shouldThrow = true;

        renderOfficeShell();

        await user.click(
            await screen.findByRole('button', {
                name: /load 3d office/i,
            }),
        );

        expect(
            await screen.findByRole('heading', {
                name: 'The 3D office could not be initialized',
            }),
        ).toBeInTheDocument();

        expect(
            screen.getByRole('link', {
                name: /continue in operational dashboard/i,
            }),
        ).toHaveAttribute('href', '/operations');

        expect(screen.getByText('Backend Engineer')).toBeInTheDocument();

        officeCanvasMock.shouldThrow = false;

        await user.click(
            screen.getByRole('button', {
                name: /retry with low quality/i,
            }),
        );

        expect(await screen.findByTestId('mock-office-canvas')).toHaveAttribute(
            'data-quality-preset',
            'low',
        );
    });
});
