import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

function provisionDevelopmentExecution(): {
    email: string;
    password: string;
    queuePath: string;
} {
    const suffix = randomUUID().replaceAll('-', '').slice(0, 20);
    const email = `development-inspector-${suffix}@example.com`;
    const password = 'DevelopmentBrowser123!';
    const organizationSlug = `development-org-${suffix}`;
    const projectSlug = `development-project-${suffix}`;
    const stableId = `E2E-${suffix.toUpperCase()}`;
    const php = [
        `$fixture = \\Tests\\Support\\TicketTestFixture::create(`,
        `    stableId: ${JSON.stringify(stableId)},`,
        `    ticketAttributes: [`,
        `        'status' => \\App\\Domain\\Tickets\\TicketStatus::Ready,`,
        `        'desired_state' => \\App\\Domain\\Tickets\\TicketStatus::Ready,`,
        `        'status_changed_at' => now()->subMinute(),`,
        `        'ready_at' => now()->subMinute(),`,
        `    ],`,
        `);`,
        `$organization = $fixture['project']->organization;`,
        `$organization->forceFill(['name' => 'Development Browser Organization', 'slug' => ${JSON.stringify(organizationSlug)}])->save();`,
        `$project = $fixture['project'];`,
        `$project->forceFill(['name' => 'Development Browser Project', 'slug' => ${JSON.stringify(projectSlug)}])->save();`,
        `$fixture['roadmap']->forceFill([`,
        `    'status' => 'approved',`,
        `    'approved_fingerprint' => $fixture['roadmap']->candidate_fingerprint,`,
        `    'approved_snapshot' => ['schema_version' => 1],`,
        `    'approved_at' => now(),`,
        `])->save();`,
        `$user = \\App\\Models\\User::factory()->create([`,
        `    'name' => 'Development Browser User',`,
        `    'email' => ${JSON.stringify(email)},`,
        `    'password' => ${JSON.stringify(password)},`,
        `    'email_verified_at' => now(),`,
        `]);`,
        `\\App\\Models\\OrganizationMembership::factory()->for($organization)->for($user)->owner()->create();`,
        `$execution = \\App\\Models\\Execution::factory()->for($project)->create([`,
        `    'project_context_snapshot_id' => $fixture['contextSnapshot']->id,`,
        `    'capability' => 'development.simulation',`,
        `]);`,
        `$now = \\Carbon\\CarbonImmutable::now();`,
        `\\App\\Models\\TicketExecutionLease::query()->create([`,
        `    'project_id' => $project->id,`,
        `    'roadmap_task_id' => $fixture['ticket']->id,`,
        `    'execution_id' => $execution->id,`,
        `    'owner' => 'development-browser-worker',`,
        `    'acquired_at' => $now,`,
        `    'heartbeat_at' => $now,`,
        `    'expires_at' => $now->addMinutes(5),`,
        `]);`,
        `app(\\App\\Application\\Development\\ProcessDevelopmentExecution::class)->handle($execution, seed: 102);`,
    ].join('\n');

    execFileSync('php', ['artisan', 'tinker', `--execute=${php}`], {
        cwd: process.cwd(),
        env: process.env,
        stdio: 'pipe',
    });

    return {
        email,
        password,
        queuePath: `/organizations/${organizationSlug}/projects/${projectSlug}/development`,
    };
}

async function login(
    page: Page,
    email: string,
    password: string,
): Promise<void> {
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await page.waitForURL(/\/dashboard$/);
}

test('keyboard navigation opens the safe Layer 2 inspector without console errors', async ({
    page,
}) => {
    const fixture = provisionDevelopmentExecution();
    const consoleErrors: string[] = [];
    page.on('console', (message) => {
        if (message.type() === 'error') {
            consoleErrors.push(message.text());
        }
    });
    await login(page, fixture.email, fixture.password);

    const response = await page.goto(fixture.queuePath);
    expect(response?.ok()).toBeTruthy();
    await expect(
        page.getByRole('heading', { name: 'Development queue' }),
    ).toBeVisible();
    const inspectorLink = page.getByRole('link', { name: 'Inspect execution' });
    await inspectorLink.focus();
    await page.keyboard.press('Enter');

    await expect(
        page.getByRole('heading', { name: 'Layer 2 execution inspector' }),
    ).toBeVisible();
    await expect(
        page.getByText('Simulated execution', { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByText('Unverified result', { exact: true }),
    ).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Attempts' })).toBeVisible();
    expect(consoleErrors).toEqual([]);
});
