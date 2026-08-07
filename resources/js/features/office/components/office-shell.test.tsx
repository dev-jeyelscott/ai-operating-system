import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
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
    default: ({
        qualityPreset,
        projection,
        onSelectAgent,
    }: {
        qualityPreset: string;
        projection: ReturnType<typeof officeProjectionFixture>;
        onSelectAgent: (agentId: string) => void;
    }) => {
        if (officeCanvasMock.shouldThrow) {
            throw new Error('Simulated renderer initialization failure.');
        }

        return (
            <div
                data-testid="mock-office-canvas"
                data-quality-preset={qualityPreset}
            >
                <button
                    type="button"
                    onClick={() => onSelectAgent(projection.agents[0].id)}
                >
                    Select first 3D agent
                </button>
            </div>
        );
    },
}));

import { OfficeShell } from './office-shell';

/**
 * Render the shell with deterministic projection, telemetry endpoint, and
 * renderer capability.
 */
function renderOfficeShell(
    capability: OfficeRendererCapability = SUPPORTED_OFFICE_RENDERER_CAPABILITY,
) {
    const projection = officeProjectionFixture();

    const view = render(
        <OfficeShell
            projection={projection}
            operationsUrl="/operations"
            telemetryEndpointUrl="/test/office-telemetry"
            rendererCapabilityDetector={() => capability}
        />,
    );

    return {
        projection,
        view,
    };
}

describe('OfficeShell accessibility bridge', () => {
    beforeEach(() => {
        officeCanvasMock.shouldThrow = false;
    });

    it('navigates rooms from the focusable canvas region', async () => {
        const user = userEvent.setup();

        renderOfficeShell();

        const canvasRegion = await screen.findByRole('region', {
            name: /interactive 3d office navigation/i,
        });

        canvasRegion.focus();
        await user.keyboard('{ArrowRight}');

        expect(
            screen.getByText(/selected room: planning room/i),
        ).toBeInTheDocument();

        expect(canvasRegion).toHaveFocus();
    });

    it('opens an agent inspector from the accessible list', async () => {
        const user = userEvent.setup();
        const { projection } = renderOfficeShell();

        const trigger = screen.getAllByRole('button', {
            name: /inspect agent/i,
        })[0];

        await user.click(trigger);

        expect(screen.getByRole('dialog')).toBeInTheDocument();

        expect(
            screen.getByRole('heading', {
                name: projection.agents[0].role,
            }),
        ).toBeInTheDocument();

        await user.keyboard('{Escape}');

        expect(trigger).toHaveFocus();
    });

    it('opens the same inspector from the 3D selection callback', async () => {
        const user = userEvent.setup();
        const { projection } = renderOfficeShell();

        await user.click(
            await screen.findByRole('button', {
                name: /load 3d office/i,
            }),
        );

        const canvasRegion = screen.getByRole('region', {
            name: /interactive 3d office navigation/i,
        });

        canvasRegion.focus();

        await user.click(
            await screen.findByRole('button', {
                name: /select first 3d agent/i,
            }),
        );

        expect(
            screen.getByRole('heading', {
                name: projection.agents[0].role,
            }),
        ).toBeInTheDocument();

        await user.keyboard('{Escape}');

        expect(canvasRegion).toHaveFocus();
    });

    it('keeps keyboard controls and agent state during WebGL fallback', async () => {
        const user = userEvent.setup();
        const { projection } = renderOfficeShell(
            UNAVAILABLE_OFFICE_RENDERER_CAPABILITY,
        );

        expect(
            await screen.findByRole('heading', {
                name: '3D office unavailable',
            }),
        ).toBeInTheDocument();

        const canvasRegion = screen.getByRole('region', {
            name: /interactive 3d office navigation/i,
        });

        canvasRegion.focus();
        await user.keyboard('{End}');

        expect(screen.getByText(/selected room: archive/i)).toBeInTheDocument();

        expect(screen.getByText(projection.agents[0].role)).toBeInTheDocument();
    });

    it('keeps simulation status visible', () => {
        renderOfficeShell();

        expect(
            screen.getByText('Simulation remains unverified'),
        ).toBeInTheDocument();
    });
});
