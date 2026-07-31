import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

type PreparedFixture = {
    email: string;
    password: string;
    projectId: number;
    operationsUrl: string;
};

/**
 * Execute the testing-only fixture inside the same Laravel environment as CI.
 */
function runFixture<T>(
    action: 'prepare' | 'activate',
    environment: Record<string, string> = {},
): T {
    const output = execFileSync(
        'php',
        [
            'artisan',
            'tinker',
            '--execute',
            "require base_path('tests/e2e/fixtures/operations-reconnect.php');",
        ],
        {
            cwd: process.cwd(),
            encoding: 'utf8',
            env: {
                ...process.env,
                APP_ENV: 'testing',
                AIOS_E2E_ACTION: action,
                ...environment,
            },
        },
    );

    const jsonLine = output
        .trim()
        .split(/\r?\n/)
        .findLast((line) => line.trim().startsWith('{'));

    if (!jsonLine) {
        throw new Error(
            `The ${action} fixture did not return a JSON payload.`,
        );
    }

    return JSON.parse(jsonLine) as T;
}

test('reconstructs dashboard state after reconnect', async ({
    page,
    context,
}) => {
    test.setTimeout(60_000);

    const fixture = runFixture<PreparedFixture>('prepare');

    await page.goto('/login');
    await page.getByLabel('Email address').fill(fixture.email);
    await page.getByLabel('Password').fill(fixture.password);
    await page.getByRole('button', { name: 'Log in' }).click();

    await page.goto(fixture.operationsUrl);

    await expect(
        page.getByRole('heading', {
            name: 'Operational dashboard',
        }),
    ).toBeVisible();

    const originalFingerprint = await page
        .getByText(/Fingerprint/)
        .textContent();

    expect(originalFingerprint).not.toBeNull();

    await context.setOffline(true);

    /*
     * Persist authoritative state while browser delivery is unavailable.
     * No production HTTP mutation endpoint is introduced.
     */
    runFixture<{ executionId: string }>('activate', {
        AIOS_E2E_PROJECT_ID: String(fixture.projectId),
    });

    await context.setOffline(false);

    await expect
        .poll(
            async () => {
                await page.reload({
                    waitUntil: 'domcontentloaded',
                });

                return page
                    .getByText(/Fingerprint/)
                    .textContent();
            },
            {
                timeout: 30_000,
            },
        )
        .not.toBe(originalFingerprint);

    await expect(
        page.getByText('Backend Engineer'),
    ).toBeVisible();

    await expect(
        page.getByText('Running').first(),
    ).toBeVisible();
});
