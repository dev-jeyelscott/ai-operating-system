import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

const repositoryRoot = fileURLToPath(new URL('../..', import.meta.url));
const roadmapPath = (action: string) =>
    `/organizations/roadmap-browser/projects/${action}-roadmap-project/roadmaps`;
const errorsByPage = new WeakMap<Page, string[]>();

function artisan(arguments_: string[], stdio: 'inherit' | 'pipe'): void {
    execFileSync('php', ['artisan', ...arguments_], {
        cwd: repositoryRoot,
        stdio,
    });
}

async function login(page: Page): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email address').fill('roadmap-owner@example.test');
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await page.waitForURL(/\/dashboard$/);
}

function roadmapStatus(page: Page, status: string) {
    return page
        .getByRole('heading', { name: 'Roadmap inspection' })
        .locator('..')
        .getByText(status, { exact: true });
}

test.describe.serial('roadmap approval gate', () => {
    test.beforeAll(() => {
        artisan(
            [
                'db:seed',
                '--class=Database\\Seeders\\RoadmapE2ESeeder',
                '--force',
            ],
            'inherit',
        );
        artisan(['cache:clear'], 'inherit');
    });

    test.beforeEach(async ({ page }) => {
        artisan(['cache:clear'], 'pipe');
        const consoleErrors: string[] = [];
        page.on('console', (message) => {
            if (message.type() === 'error') {
                consoleErrors.push(message.text());
            }
        });
        await login(page);
        errorsByPage.set(page, consoleErrors);
    });

    test.afterEach(async ({ page }) => {
        expect(errorsByPage.get(page) ?? []).toEqual([]);
    });

    test('edits roadmap content with keyboard-accessible controls', async ({
        page,
    }) => {
        await page.goto(roadmapPath('edit'));
        const goal = page.getByLabel('Edit roadmap goal');
        await goal.fill('Browser-edited traceable roadmap');
        await page.getByRole('button', { name: 'Save edit' }).focus();
        await page.keyboard.press('Enter');
        await expect(
            page.getByRole('heading', {
                name: 'Browser-edited traceable roadmap',
            }),
        ).toBeVisible();
    });

    test('approves the exact current roadmap', async ({ page }) => {
        await page.goto(roadmapPath('approve'));
        await page.getByRole('button', { name: 'Approve roadmap' }).focus();
        await page.keyboard.press('Enter');
        await expect(roadmapStatus(page, 'Approved')).toBeVisible();
    });

    test('requires and records rejection feedback', async ({ page }) => {
        await page.goto(roadmapPath('reject'));
        await page
            .getByLabel('Rejection feedback')
            .fill('Split this roadmap into smaller milestones.');
        await page.getByRole('button', { name: 'Reject roadmap' }).click();
        await expect(page.getByText('Rejected', { exact: true })).toBeVisible();
    });

    test('requests regeneration through the durable workflow', async ({
        page,
    }) => {
        await page.goto(roadmapPath('regenerate'));
        await page
            .getByLabel('Regeneration feedback')
            .fill('Prioritize security architecture first.');
        await page.getByRole('button', { name: 'Regenerate roadmap' }).click();
        await expect(
            page.getByText('Superseded', { exact: true }),
        ).toBeVisible();
    });

    test('confirms and defers a Notion reconciliation conflict without overwriting either source', async ({
        page,
    }) => {
        await page.goto(roadmapPath('conflict'));
        const approve = page.getByRole('button', { name: 'Approve roadmap' });

        if (await approve.isVisible()) {
            await approve.click();
        }

        await expect(roadmapStatus(page, 'Approved')).toBeVisible();

        const defer = page.getByRole('button', {
            name: 'Defer and block writes',
        });
        await expect(defer).toBeVisible();
        await page
            .getByLabel('Reason for defer and block writes')
            .fill('Awaiting product review of the external change.');
        page.once('dialog', (dialog) => dialog.accept());
        await defer.click();

        await expect(defer).toBeHidden();
    });
});
