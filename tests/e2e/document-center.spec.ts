import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { expect, test  } from '@playwright/test';
import type {Page} from '@playwright/test';

const repositoryRoot = fileURLToPath(
    new URL('../..', import.meta.url),
);

const fixturePath = fileURLToPath(
    new URL('./fixtures/architecture.md', import.meta.url),
);

const documentsUrl =
    '/organizations/e2e-document-center/projects/document-workflow-project/documents';

/**
 * Run one Laravel Artisan command from the repository root.
 */
function artisan(...arguments_: string[]): void {
    execFileSync('php', ['artisan', ...arguments_], {
        cwd: repositoryRoot,
        stdio: 'inherit',
    });
}

/**
 * Process the real queued document lifecycle until no jobs remain.
 */
function drainDocumentQueue(): void {
    artisan(
        'queue:work',
        '--stop-when-empty',
        '--tries=1',
        '--timeout=30',
    );
}

/**
 * Authenticate through the visible browser interface.
 */
async function login(page: Page): Promise<void> {
    await page.goto('/login');

    await page
        .getByLabel('Email address')
        .fill('document-owner@example.test');

    await page.getByLabel('Password').fill('password');

    await page.getByRole('button', {
        name: 'Log in',
    }).click();

    await page.waitForURL(/\/dashboard$/);
}

test.describe.serial('document center workflow', () => {
    test.beforeAll(() => {
        artisan(
            'db:seed',
            '--class=Database\\Seeders\\DocumentCenterE2ESeeder',
            '--force',
        );
    });

    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test('uploads, processes, and approves a document through visible controls', async ({
        page,
    }) => {
        await page.goto(documentsUrl);

        await page
            .getByLabel('Document title')
            .fill('Browser architecture document');

        await page
            .getByLabel('Document class')
            .fill('architecture');

        await page
            .getByLabel('Document file')
            .setInputFiles(fixturePath);

        await page
            .getByRole('button', {
                name: 'Upload document',
            })
            .click();

        await expect(
            page.getByText(
                'Document uploaded. Processing has been queued.',
            ),
        ).toBeVisible();

        drainDocumentQueue();

        await page.reload();

        await page
            .getByRole('link', {
                name: 'Browser architecture document',
            })
            .click();

        await expect(
            page.getByText('Needs review', {
                exact: true,
            }),
        ).toBeVisible();

        page.once('dialog', async (dialog) => {
            expect(dialog.type()).toBe('confirm');
            await dialog.accept();
        });

        const approve = page.getByRole('button', {
            name: 'Approve version 1',
        });

        await approve.focus();
        await page.keyboard.press('Enter');

        await expect(
            page.getByText('Document version approved.'),
        ).toBeVisible();

        await expect(
            page.getByText('Approved', {
                exact: true,
            }),
        ).toBeVisible();
    });

    test('retries a failed analysis through visible controls', async ({
        page,
    }) => {
        await page.goto(documentsUrl);

        await page
            .getByRole('link', {
                name: 'Recoverable architecture document',
            })
            .click();

        await expect(
            page.getByText(
                'The document analysis could not be completed.',
            ),
        ).toBeVisible();

        const retry = page.getByRole('button', {
            name: 'Retry processing version 1',
        });

        await retry.focus();
        await page.keyboard.press('Enter');

        await expect(
            page.getByText(
                'Document processing was queued for retry.',
            ),
        ).toBeVisible();

        drainDocumentQueue();

        await page.reload();

        await expect(
            page.getByText('Needs review', {
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            page.getByRole('button', {
                name: 'Approve version 1',
            }),
        ).toBeVisible();
    });
});
