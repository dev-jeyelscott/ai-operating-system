import { execFileSync } from 'node:child_process';
import { expect, test  } from '@playwright/test';
import type {Page} from '@playwright/test';

type OfficeFixture = {
    email: string;
    password: string;
    officeUrl: string;
    operationsUrl: string;
};

type NavigationMeasurement = {
    duration: number;
    encodedBodySize: number;
    totalEncodedResourceBytes: number;
};

/**
 * Prepare the deterministic office fixture through the existing Laravel command.
 */
function prepareOfficeFixture(): OfficeFixture {
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
            env: process.env,
            encoding: 'utf8',
        },
    );

    return JSON.parse(output.trim()) as OfficeFixture;
}

/**
 * Authenticate the deterministic office test user.
 */
async function authenticate(
    page: Page,
    fixture: OfficeFixture,
): Promise<void> {
    await page.goto('/login');

    await page.getByLabel('Email address').fill(fixture.email);

    await page
        .getByLabel('Password', {
            exact: true,
        })
        .fill(fixture.password);

    await Promise.all([
        page.waitForURL((url) => url.pathname !== '/login'),
        page
            .getByRole('button', {
                name: 'Log in',
            })
            .click(),
    ]);
}

/**
 * Read Navigation Timing Level 2 and same-page resource sizes.
 */
async function navigationMeasurement(
    page: Page,
): Promise<NavigationMeasurement> {
    return page.evaluate(() => {
        const navigation = performance.getEntriesByType(
            'navigation',
        )[0] as PerformanceNavigationTiming;

        const resources = performance.getEntriesByType(
            'resource',
        ) as PerformanceResourceTiming[];

        return {
            duration: navigation.duration,
            encodedBodySize: navigation.encodedBodySize,
            totalEncodedResourceBytes: resources.reduce(
                (total, resource) =>
                    total + resource.encodedBodySize,
                0,
            ),
        };
    });
}

test('operations dashboard remains inside the browser navigation budget', async ({
    page,
}) => {
    const fixture = prepareOfficeFixture();

    await authenticate(page, fixture);

    const response = await page.goto(
        fixture.operationsUrl,
        {
            waitUntil: 'domcontentloaded',
        },
    );

    expect(response?.ok()).toBeTruthy();

    await expect(
        page.getByRole('heading', {
            level: 1,
            name: 'Operational dashboard',
        }),
    ).toBeVisible();

    const measurement = await navigationMeasurement(page);

    expect(measurement.duration).toBeLessThanOrEqual(
        Number(
            process.env
                .PERFORMANCE_OPERATIONS_MAX_NAVIGATION_MS
                ?? 5000,
        ),
    );
});

test('office navigation and resources remain inside browser budgets', async ({
    page,
}) => {
    const fixture = prepareOfficeFixture();

    await authenticate(page, fixture);

    const response = await page.goto(fixture.officeUrl, {
        waitUntil: 'domcontentloaded',
    });

    expect(response?.ok()).toBeTruthy();

    const measurement = await navigationMeasurement(page);

    expect(measurement.duration).toBeLessThanOrEqual(
        Number(
            process.env.PERFORMANCE_OFFICE_MAX_NAVIGATION_MS
                ?? 6000,
        ),
    );

    expect(
        measurement.totalEncodedResourceBytes,
    ).toBeLessThanOrEqual(
        Number(
            process.env
                .PERFORMANCE_OFFICE_MAX_ENCODED_RESOURCE_BYTES
                ?? 5_000_000,
        ),
    );
});
