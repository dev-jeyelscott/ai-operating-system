import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    /**
     * Only Playwright should collect specifications from this directory.
     */
    testDir: './tests/e2e',

    /**
     * Browser fixtures share one database and must not race their seeders.
     */
    fullyParallel: false,
    workers: 1,

    /**
     * Prevent accidentally committed test.only calls from passing CI.
     */
    forbidOnly: Boolean(process.env.CI),

    /**
     * Retry browser failures in CI while preserving diagnostic evidence.
     */
    retries: process.env.CI ? 2 : 0,

    /**
     * Generate an interactive HTML report after the test run.
     */
    reporter: 'html',

    use: {
        /**
         * Allow CI or another environment to override the application URL.
         */
        baseURL:
            process.env.PLAYWRIGHT_BASE_URL ??
            'http://localhost',

        /**
         * Preserve diagnostic evidence for failed or retried tests.
         */
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
    },

    projects: [
        {
            name: 'office-auth',
            testMatch: /office-auth\.setup\.ts/,
        },
        {
            name: 'chromium',
            testIgnore: /office-auth\.setup\.ts/,
            dependencies: ['office-auth'],
            use: {
                ...devices['Desktop Chrome'],
            },
        },
    ],
});
