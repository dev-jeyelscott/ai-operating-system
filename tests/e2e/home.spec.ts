import { expect, test } from '@playwright/test';

test('loads the application home page', async ({ page }) => {
    // Verify that Laravel and the Inertia frontend respond successfully.
    const response = await page.goto('/');

    expect(response?.ok()).toBe(true);
    await expect(page.locator('body')).toBeVisible();
});
