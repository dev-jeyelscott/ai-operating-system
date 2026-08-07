import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import path from 'node:path';
import { expect, test as setup } from '@playwright/test';

type OfficeAuthenticationFixture = {
    email: string;
    password: string;
};

const authenticationFile = path.resolve(
    process.cwd(),
    'playwright/.auth/office.json',
);

/**
 * Prepare the deterministic Laravel-owned office account before authentication.
 */
function prepareOfficeAuthenticationFixture(): OfficeAuthenticationFixture {
    const output = execFileSync(
        'php',
        [
            'artisan',
            'app:e2e:prepare-office',
            '--sequence=42',
            '--json',
        ],
        {
            cwd: process.cwd(),
            encoding: 'utf8',
            env: process.env,
        },
    );

    const jsonLine = output
        .trim()
        .split('\n')
        .filter(Boolean)
        .at(-1);

    if (!jsonLine) {
        throw new Error(
            'The office E2E fixture command returned no JSON.',
        );
    }

    return JSON.parse(jsonLine) as OfficeAuthenticationFixture;
}

/**
 * Authenticate once and persist the Laravel browser session for office tests.
 */
setup('authenticate office E2E user', async ({ page }) => {
    const fixture = prepareOfficeAuthenticationFixture();

    mkdirSync(path.dirname(authenticationFile), {
        recursive: true,
    });

    await page.goto('/login');

    await page
        .getByLabel('Email address', {
            exact: true,
        })
        .fill(fixture.email);

    await page
        .getByLabel('Password', {
            exact: true,
        })
        .fill(fixture.password);

    const loginResponsePromise = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname === '/login',
    );

    await page
        .getByRole('button', {
            name: 'Log in',
            exact: true,
        })
        .click();

    const loginResponse = await loginResponsePromise;

    expect(loginResponse.status()).toBeLessThan(400);

    await expect(page).toHaveURL(/\/dashboard$/);

    await page.context().storageState({
        path: authenticationFile,
    });
});
