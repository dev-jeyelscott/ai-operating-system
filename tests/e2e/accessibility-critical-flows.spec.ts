import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { expectNoAccessibilityViolations } from './support/accessibility';

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

/**
 * Prepare the existing deterministic Laravel-owned accessibility fixture.
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
 * Authenticate using the real Laravel and Inertia login flow.
 */
async function login(page: Page, fixture: OfficeFixture) {
    await page.goto('/login');

    await page.getByLabel(/email/i).fill(fixture.email);
    await page.getByLabel('Password', { exact: true }).fill(fixture.password);

    await page
        .getByRole('button', {
            name: /log in/i,
        })
        .click();

    await expect(page).not.toHaveURL(/\/login$/);
}

/**
 * Apply one application theme without changing business or workflow state.
 */
async function applyTheme(page: Page, theme: 'light' | 'dark') {
    await page.evaluate((selectedTheme) => {
        document.documentElement.classList.toggle(
            'dark',
            selectedTheme === 'dark',
        );

        document.documentElement.style.colorScheme = selectedTheme;
    }, theme);
}

/**
 * Blur the current element so the next Tab press starts from the document's
 * first keyboard-focusable control.
 */
async function resetKeyboardFocus(page: Page) {
    await page.evaluate(() => {
        const activeElement = document.activeElement;

        if (activeElement instanceof HTMLElement) {
            activeElement.blur();
        }
    });
}

test.describe('AIOS-149 critical accessibility flows', () => {
    test.describe.configure({
        mode: 'serial',
    });

    let fixture: OfficeFixture;

    test.beforeEach(() => {
        fixture = prepareOfficeFixture();
    });

    test('passes login and operational dashboard accessibility checks', async ({
        page,
    }, testInfo) => {
        await page.goto('/login');

        await expectNoAccessibilityViolations(
            page,
            testInfo,
            'login-light-theme',
        );

        await applyTheme(page, 'dark');

        await expectNoAccessibilityViolations(
            page,
            testInfo,
            'login-dark-theme',
        );

        await login(page, fixture);
        await page.goto(fixture.operationsUrl);

        await expect(
            page.getByRole('heading', {
                name: 'Operational dashboard',
            }),
        ).toBeVisible();

        /*
         * The authenticated application must expose exactly one primary
         * landmark, owned by the shared application layout.
         */
        await expect(page.getByRole('main')).toHaveCount(1);

        const mainContent = page.locator('#main-content');

        await expect(mainContent).toHaveCount(1);

        /*
         * Verify the WCAG bypass mechanism and programmatic focus destination.
         */
        await resetKeyboardFocus(page);
        await page.keyboard.press('Tab');

        const skipLink = page.getByRole('link', {
            name: 'Skip to main content',
        });

        await expect(skipLink).toBeFocused();

        await page.keyboard.press('Enter');

        await expect(mainContent).toBeFocused();

        await applyTheme(page, 'light');

        await expectNoAccessibilityViolations(
            page,
            testInfo,
            'operations-dashboard-light-theme',
        );

        await applyTheme(page, 'dark');

        await expectNoAccessibilityViolations(
            page,
            testInfo,
            'operations-dashboard-dark-theme',
        );
    });

    test('keeps office navigation and inspector accessible', async ({
        page,
    }, testInfo) => {
        await login(page, fixture);
        await page.goto(fixture.officeUrl);

        await expect(
            page.getByRole('heading', {
                name: 'Interactive office',
            }),
        ).toBeVisible();

        await expect(page.getByRole('main')).toHaveCount(1);

        await expectNoAccessibilityViolations(
            page,
            testInfo,
            'office-initial-state',
        );

        const canvasRegion = page.getByRole('region', {
            name: /interactive 3d office navigation/i,
        });

        await canvasRegion.focus();

        await page.keyboard.press('ArrowRight');
        await page.keyboard.press('ArrowRight');
        await page.keyboard.press('Enter');

        const inspector = page.getByRole('dialog');

        await expect(inspector).toBeVisible();

        await expect(
            inspector.getByRole('heading', {
                name: fixture.agentRole,
            }),
        ).toBeVisible();

        await expect(inspector.getByText(fixture.agentAction)).toBeVisible();

        await expect(
            inspector.getByText('Simulated', {
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            inspector.getByText('Unverified', {
                exact: true,
            }),
        ).toBeVisible();

        await expectNoAccessibilityViolations(
            page,
            testInfo,
            'office-agent-inspector-open',
        );

        await page.keyboard.press('Escape');

        await expect(inspector).toBeHidden();
        await expect(canvasRegion).toBeFocused();

        const inspectButton = page
            .locator(`[data-office-agent-inspect="${fixture.agentId}"]`)
            .first();

        await inspectButton.click();

        await expect(inspector).toBeVisible();

        await page.keyboard.press('Escape');

        await expect(inspectButton).toBeFocused();
    });

    test('passes reduced-motion and WebGL fallback checks', async ({
        page,
    }, testInfo) => {
        await login(page, fixture);

        await page.emulateMedia({
            reducedMotion: 'reduce',
        });

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

        await page.goto(fixture.officeUrl);

        await expect(page.getByText('Reduced motion enabled')).toBeVisible();

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

        const scrollBehavior = await page.evaluate(
            () => getComputedStyle(document.documentElement).scrollBehavior,
        );

        expect(scrollBehavior).toBe('auto');

        await expectNoAccessibilityViolations(
            page,
            testInfo,
            'office-reduced-motion-webgl-fallback',
        );
    });
});
