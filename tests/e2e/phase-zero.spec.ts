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
});
