import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { expect, test } from '@playwright/test';

/**
 * Provision a verified project owner and project through Laravel factories.
 */
function provisionAuditProject(): {
    email: string;
    password: string;
    auditPath: string;
} {
    const suffix = randomUUID().replaceAll('-', '').slice(0, 20);

    const email = `project-audit-${suffix}@example.com`;
    const password = 'AuditBrowserTest123!';
    const organizationSlug = `audit-org-${suffix}`;
    const projectSlug = `audit-project-${suffix}`;

    const php = [
        `$user = \\App\\Models\\User::factory()->create([`,
        `    'name' => 'Project Audit Browser User',`,
        `    'email' => ${JSON.stringify(email)},`,
        `    'password' => ${JSON.stringify(password)},`,
        `    'email_verified_at' => now(),`,
        `]);`,
        `$organization = \\App\\Models\\Organization::factory()->create([`,
        `    'name' => 'Project Audit Browser Organization',`,
        `    'slug' => ${JSON.stringify(organizationSlug)},`,
        `]);`,
        `\\App\\Models\\OrganizationMembership::factory()`,
        `    ->for($organization)`,
        `    ->for($user)`,
        `    ->owner()`,
        `    ->create();`,
        `\\App\\Models\\Project::factory()`,
        `    ->for($organization)`,
        `    ->create([`,
        `        'name' => 'Project Audit Browser Project',`,
        `        'slug' => ${JSON.stringify(projectSlug)},`,
        `    ]);`,
    ].join('\n');

    execFileSync('php', ['artisan', 'tinker', `--execute=${php}`], {
        cwd: process.cwd(),
        env: process.env,
        stdio: 'pipe',
    });

    return {
        email,
        password,
        auditPath:
            `/organizations/${organizationSlug}` +
            `/projects/${projectSlug}/audit`,
    };
}

test('browser navigation to the Audit route renders the page heading', async ({
    page,
}) => {
    const fixture = provisionAuditProject();

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

    const response = await page.goto(fixture.auditPath);

    expect(response?.ok()).toBeTruthy();

    await expect(
        page.getByRole('heading', {
            level: 1,
            name: 'Execution and audit',
        }),
    ).toBeVisible();

    await expect(
        page.getByRole('heading', {
            level: 3,
            name: 'No matching audit events',
        }),
    ).toBeVisible();
});
