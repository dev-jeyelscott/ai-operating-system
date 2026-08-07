import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

type OfficeFixture = {
    email: string;
    password: string;
    officeUrl: string;
    operationsUrl: string;
    agentId: string;
    agentRole: string;
    agentAction: string;
    projectionSequence: number;
};

const officeAuthenticationFile =
    'playwright/.auth/office.json';

test.use({
    storageState: officeAuthenticationFile,
});

/**
 * Create or update the deterministic Laravel-owned office fixture.
 */
function prepareOfficeFixture(sequence = 42): OfficeFixture {
    const output = execFileSync(
        'php',
        [
            'artisan',
            'app:e2e:prepare-office',
            `--sequence=${sequence}`,
            '--json',
        ],
        {
            cwd: process.cwd(),
            encoding: 'utf8',
            env: process.env,
        },
    );

    const jsonLine = output.trim().split('\n').filter(Boolean).at(-1);

    if (!jsonLine) {
        throw new Error('The office E2E fixture command returned no JSON.');
    }

    return JSON.parse(jsonLine) as OfficeFixture;
}

/**
 * Authenticate and open the exact tenant-scoped office route.
 */
async function openOffice(page: Page, fixture: OfficeFixture) {
    await page.goto(fixture.officeUrl);

    await expect(
        page.getByRole('heading', {
            name: 'Interactive office',
        }),
    ).toBeVisible();

    await expect(page.getByText('Simulation remains unverified')).toBeVisible();
}

test.describe('3D office state consistency', () => {
    test.describe.configure({
        mode: 'serial',
        timeout: 60_000,
    });

    let fixture: OfficeFixture;

    /*
     * The office authentication setup project already created the Laravel
     * session stored in playwright/.auth/office.json. Reset only the
     * authoritative office fixture before each test.
     */
    test.beforeEach(async ({ page }) => {
        fixture = prepareOfficeFixture(42);

        await openOffice(page, fixture);
    });

    test('reconstructs authoritative state after a full refresh', async ({
        page,
    }) => {
        const developmentRoom = page.locator(
            '[data-office-room-control="development_floor"]',
        );

        await expect(developmentRoom).toContainText('Working');
        await expect(page.getByText(fixture.agentRole)).toBeVisible();
        await expect(page.getByText(fixture.agentAction)).toBeVisible();
        await expect(
            page.getByText(String(fixture.projectionSequence), {
                exact: true,
            }),
        ).toBeVisible();

        await page.reload();

        await expect(developmentRoom).toContainText('Working');
        await expect(page.getByText(fixture.agentRole)).toBeVisible();
        await expect(page.getByText(fixture.agentAction)).toBeVisible();
        await expect(
            page.getByText(String(fixture.projectionSequence), {
                exact: true,
            }),
        ).toBeVisible();
    });

    test('recovers the updated projection after offline reconnect', async ({
        page,
        context,
    }) => {
        await context.setOffline(true);

        const updatedFixture = prepareOfficeFixture(43);

        await expect(page.getByText(fixture.agentAction)).toBeVisible();

        await context.setOffline(false);

        const refreshResponse = page.waitForResponse(
            (response) =>
                response.url() === updatedFixture.officeUrl &&
                response.request().method() === 'GET',
        );

        await page
            .getByRole('button', {
                name: 'Refresh',
            })
            .click();

        await refreshResponse;

        await expect(page.getByText(updatedFixture.agentAction)).toBeVisible();

        await expect(
            page.getByText(String(updatedFixture.projectionSequence), {
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            page.locator('[data-office-room-control="development_floor"]'),
        ).toContainText('Validating');
    });

    test('preserves controls and state with reduced motion', async ({
        page,
    }) => {
        await page.emulateMedia({
            reducedMotion: 'reduce',
        });

        await page.reload();

        await expect(page.getByText('Reduced motion enabled')).toBeVisible();

        const canvasRegion = page.getByRole('region', {
            name: /interactive 3d office navigation/i,
        });

        await canvasRegion.focus();
        await page.keyboard.press('ArrowRight');
        await page.keyboard.press('ArrowRight');

        await expect(
            page.getByText(/selected room: development floor/i),
        ).toBeVisible();

        await expect(page.getByText(fixture.agentRole)).toBeVisible();
    });

    test('keeps the authoritative DOM interface when WebGL is unavailable', async ({
        page,
    }) => {
        await page.addInitScript(() => {
            const originalGetContext = HTMLCanvasElement.prototype.getContext;

            Object.defineProperty(HTMLCanvasElement.prototype, 'getContext', {
                configurable: true,
                value(
                    this: HTMLCanvasElement,
                    contextId: string,
                    ...args: unknown[]
                ) {
                    if (contextId === 'webgl2') {
                        return null;
                    }

                    return Reflect.apply(originalGetContext, this, [
                        contextId,
                        ...args,
                    ]);
                },
            });
        });

        await page.reload();

        await expect(
            page.getByRole('heading', {
                name: '3D office unavailable',
            }),
        ).toBeVisible();

        await expect(
            page.getByRole('navigation', {
                name: /office room navigation/i,
            }),
        ).toBeVisible();

        await expect(page.getByText(fixture.agentRole)).toBeVisible();

        await expect(
            page.getByRole('link', {
                name: /continue in operational dashboard/i,
            }),
        ).toHaveAttribute('href', fixture.operationsUrl);

        await expect(
            page.getByRole('button', {
                name: /load 3d office/i,
            }),
        ).toHaveCount(0);
    });

    test('keeps the canvas keyboard bridge and DOM inspector consistent', async ({
        page,
    }) => {
        const canvasRegion = page.getByRole('region', {
            name: /interactive 3d office navigation/i,
        });

        await canvasRegion.focus();
        await page.keyboard.press('ArrowRight');
        await page.keyboard.press('ArrowRight');
        await page.keyboard.press('Enter');

        const dialog = page.getByRole('dialog');

        await expect(dialog).toBeVisible();
        await expect(
            dialog.getByRole('heading', {
                name: fixture.agentRole,
            }),
        ).toBeVisible();
        await expect(dialog.getByText(fixture.agentAction)).toBeVisible();
        await expect(
            dialog.getByText('Simulated', {
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            dialog.getByText('Unverified', {
                exact: true,
            }),
        ).toBeVisible();

        await page.keyboard.press('Escape');

        await expect(dialog).toBeHidden();
        await expect(canvasRegion).toBeFocused();

        const inspectButton = page
            .locator(`[data-office-agent-inspect="${fixture.agentId}"]`)
            .first();

        await inspectButton.click();

        await expect(dialog).toBeVisible();
        await expect(
            dialog.getByRole('heading', {
                name: fixture.agentRole,
            }),
        ).toBeVisible();

        await page.keyboard.press('Escape');

        await expect(inspectButton).toBeFocused();
    });
});
