import { execFileSync } from 'node:child_process';
import { expect, test, type Locator, type Page } from '@playwright/test';

type DemoProject = {
    name: string;
    slug: string;
    status: string;
    url: string;
};

type DemoManifest = {
    schemaVersion: number;
    credentials: {
        email: string;
        password: string;
    };
    simulation: {
        provider: string;
        actualState: string;
        evidenceStillRequired: boolean;
    };
    projects: DemoProject[];
};

/**
 * Prepare only deterministic happy-path preconditions through Laravel.
 */
function prepareDemoManifest(): DemoManifest {
    const output = execFileSync(
        'php',
        ['artisan', 'app:demo:prepare', '--json'],
        {
            cwd: process.cwd(),
            encoding: 'utf8',
            env: {
                ...process.env,
                APP_ENV: process.env.APP_ENV ?? 'testing',
                SESSION_DRIVER: process.env.SESSION_DRIVER ?? 'database',
            },
        },
    );

    return JSON.parse(output) as DemoManifest;
}

/**
 * Reload the current application page until an authoritative element appears.
 */
async function reloadUntilVisible(
    page: Page,
    locator: Locator,
    timeout = 120_000,
): Promise<void> {
    await expect
        .poll(
            async () => {
                await page.reload();

                return locator.isVisible();
            },
            {
                timeout,
                intervals: [250, 500, 1_000, 2_000],
                message:
                    'Expected authoritative workflow state did not become visible.',
            },
        )
        .toBe(true);
}

test.describe.serial('AIOS happy-path acceptance', () => {
    let manifest: DemoManifest;
    let happyPathProject: DemoProject;

    test.beforeAll(() => {
        manifest = prepareDemoManifest();

        const project = manifest.projects.find(
            (candidate) => candidate.slug === 'demo-happy-path',
        );

        if (!project) {
            throw new Error(
                'The deterministic demo manifest does not contain demo-happy-path.',
            );
        }

        happyPathProject = project;
    });

    test('completes the user journey through an authorized simulated merge decision', async ({
        page,
    }) => {
        test.setTimeout(300_000);

        await page.goto('/login');

        await page
            .getByLabel('Email address')
            .fill(manifest.credentials.email);

        await page
            .getByLabel('Password')
            .fill(manifest.credentials.password);

        await page.getByRole('button', { name: 'Log in' }).click();

        await expect(page).toHaveURL(/\/dashboard/);

        await page.goto(happyPathProject.url);

        await expect(
            page.getByRole('heading', {
                name: 'Demo — Happy Path',
            }),
        ).toBeVisible();

        const startButton = page.getByRole('button', {
            name: 'Start this Project',
        });

        await expect(startButton).toBeEnabled();
        await startButton.click();

        await expect(
            page.getByText(/Planning|Workflow started/i),
        ).toBeVisible();

        await page.goto(happyPathProject.url);

        await page.getByRole('link', { name: 'Roadmap' }).click();

        await expect(
            page.getByRole('heading', {
                name: 'Roadmap inspection',
            }),
        ).toBeVisible();

        const approveRoadmap = page.getByRole('button', {
            name: 'Approve roadmap',
        });

        await reloadUntilVisible(page, approveRoadmap);
        await expect(approveRoadmap).toBeEnabled();
        await approveRoadmap.click();

        await expect(
            page.getByText('Approved', {
                exact: true,
            }),
        ).toBeVisible();

        const publishToNotion = page.getByRole('button', {
            name: 'Publish to Notion',
        });

        await expect(publishToNotion).toBeEnabled();
        await publishToNotion.click();

        await expect(
            page.getByText(/publication.*completed|published/i),
        ).toBeVisible({
            timeout: 120_000,
        });

        await page.goto(happyPathProject.url);

        await page.getByRole('link', { name: 'QA report' }).click();

        await expect(
            page.getByRole('heading', {
                name: 'QA report and risk matrix',
            }),
        ).toBeVisible();

        const simulationWarning = page.getByText(
            'Simulated and unverified',
        );

        await reloadUntilVisible(page, simulationWarning);

        await expect(simulationWarning).toBeVisible();
        await expect(page.getByText('develop', { exact: true })).toBeVisible();
        await expect(
            page.getByText(/evidence is still required/i),
        ).toBeVisible();

        await expect(
            page.getByText(/Actual state:\s*Unverified/i),
        ).toBeVisible();

        const approveMerge = page.getByRole('button', {
            name: 'Approve simulated merge',
        });

        await expect(approveMerge).toBeEnabled();
        await approveMerge.click();

        const confirmApproval = page.getByRole('button', {
            name: 'Confirm approve simulated merge',
        });

        await expect(confirmApproval).toBeEnabled();
        await confirmApproval.click();

        await expect(
            page.getByText(
                /terminal human decision and is now read-only/i,
            ),
        ).toBeVisible();

        await expect(
            page.getByText(
                /No repository merge will be performed/i,
            ),
        ).toBeVisible();

        await page.reload();

        await expect(
            page.getByText(
                /terminal human decision and is now read-only/i,
            ),
        ).toBeVisible();

        await expect(approveMerge).toHaveCount(0);
    });
});
