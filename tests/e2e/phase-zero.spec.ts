import { expect, test } from '@playwright/test';

test('application and readiness endpoints are available', async ({
    page,
    request,
}) => {
    const health = await request.get('/health', {
        headers: {
            Accept: 'application/json',
        },
    });

    expect(health.ok()).toBeTruthy();

    const readiness = await request.get('/ready', {
        headers: {
            Accept: 'application/json',
        },
    });

    expect(readiness.ok()).toBeTruthy();

    const response = await page.goto('/');

    expect(response?.ok()).toBeTruthy();

    await expect(page.locator('body')).toBeVisible();

    await page.goto(approvedDocumentUrl);

    const approvedSection = page.getByRole('heading', {
        name: 'Version 1',
    }).locator('..');

    await approvedSection
        .getByLabel('Replacement file for version 1')
        .setInputFiles('tests/e2e/fixtures/architecture-v2.md');

    await approvedSection
        .getByRole('button', {
            name: 'Upload replacement',
        })
        .click();

    await expect(
        page.getByRole('heading', {
            name: 'Version 1',
        }),
    ).toBeVisible();

    await expect(
        page.getByRole('heading', {
            name: 'Version 2',
        }),
    ).toBeVisible();

    await expect(
        page.getByText('quarantined'),
    ).toBeVisible();

    await expect(
        page.getByText(
            'The existing approved revision will not be superseded by this upload.',
        ),
    ).toBeVisible();
});
