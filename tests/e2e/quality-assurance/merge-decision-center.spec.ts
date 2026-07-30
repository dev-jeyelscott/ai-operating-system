import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

type QaBrowserFixture = {
    email: string;
    password: string;
    path: string;
    projectSlug: string;
};

type ReworkResult = {
    previousExecutionId: string;
    reworkExecutionId: string;
    previousAssessmentId: string;
    reworkAssessmentId: string;
};

function provisionAssessment(
    scenario: string,
    staleEvidence = false,
): QaBrowserFixture {
    const suffix = randomUUID().replaceAll('-', '').slice(0, 20);
    const email = `qa-decision-${suffix}@example.com`;
    const password = 'QualityAssuranceBrowser123!';
    const organizationSlug = `qa-org-${suffix}`;
    const projectSlug = `qa-project-${suffix}`;
    const fixtureFactory = staleEvidence
        ? '\\Tests\\Support\\CompletedQualityAssuranceFixture::createWithStaleEvidence()'
        : `\\Tests\\Support\\CompletedQualityAssuranceFixture::create(${JSON.stringify(scenario)})`;
    const php = [
        `$fixture = ${fixtureFactory};`,
        `$organization = $fixture['project']->organization;`,
        `$organization->forceFill(['name' => 'QA Browser Organization', 'slug' => ${JSON.stringify(organizationSlug)}])->save();`,
        `$project = $fixture['project'];`,
        `$project->forceFill(['name' => 'QA Browser Project', 'slug' => ${JSON.stringify(projectSlug)}])->save();`,
        `$user = \\App\\Models\\User::factory()->create([`,
        `    'name' => 'QA Browser Owner',`,
        `    'email' => ${JSON.stringify(email)},`,
        `    'password' => ${JSON.stringify(password)},`,
        `    'email_verified_at' => now(),`,
        `]);`,
        `\\App\\Models\\OrganizationMembership::factory()->for($organization)->for($user)->owner()->create();`,
    ].join('\n');

    execFileSync('php', ['artisan', 'tinker', `--execute=${php}`], {
        cwd: process.cwd(),
        env: process.env,
        stdio: 'pipe',
    });

    return {
        email,
        password,
        path: `/organizations/${organizationSlug}/projects/${projectSlug}/quality-assurance`,
        projectSlug,
    };
}

function triggerDeterministicRework(projectSlug: string): ReworkResult {
    const php = [
        `$project = \\App\\Models\\Project::query()->where('slug', ${JSON.stringify(projectSlug)})->firstOrFail();`,
        `$assessment = \\App\\Models\\QaAssessment::query()->forProject($project->id)->latest('created_at')->firstOrFail();`,
        `$ticket = \\App\\Models\\RoadmapTask::query()->whereKey($assessment->roadmap_task_id)->firstOrFail();`,
        `$previousExecutionId = $assessment->implementation_execution_id;`,
        `$execution = \\App\\Models\\Execution::factory()->for($project)->create([`,
        `    'project_context_snapshot_id' => \\App\\Models\\Execution::query()->whereKey($previousExecutionId)->value('project_context_snapshot_id'),`,
        `    'capability' => 'development.simulation',`,
        `    'logical_role' => 'backend_engineer',`,
        `]);`,
        `$selection = app(\\App\\Application\\Tickets\\SelectNextTicketAndAcquireLease::class)->handle(new \\App\\Application\\Tickets\\Data\\TicketSelectionRequest(`,
        `    organizationId: $project->organization_id,`,
        `    projectId: $project->id,`,
        `    executionId: $execution->id,`,
        `    owner: 'phase-eight-browser-rework',`,
        `));`,
        `app(\\App\\Application\\Development\\ProcessDevelopmentExecution::class)->handle($execution, 1114);`,
        `$attempt = \\App\\Models\\ExecutionAttempt::query()->where('execution_id', $execution->id)->latest('attempt_number')->firstOrFail();`,
        `$reworkAssessment = app(\\App\\Application\\QualityAssurance\\StartQualityAssuranceExecution::class)->handle(`,
        `    organizationId: $project->organization_id,`,
        `    projectId: $project->id,`,
        `    roadmapTaskId: $ticket->id,`,
        `    implementationExecutionId: $execution->id,`,
        `    implementationAttemptId: $attempt->id,`,
        `    scenario: 'merge_ready_low_risk',`,
        `    seed: 1114,`,
        `);`,
        `app(\\App\\Application\\QualityAssurance\\ProcessQualityAssuranceExecution::class)->handle($reworkAssessment);`,
        `echo 'PHASE8_REWORK:'.json_encode([`,
        `    'previousExecutionId' => $previousExecutionId,`,
        `    'reworkExecutionId' => $execution->id,`,
        `    'previousAssessmentId' => $assessment->id,`,
        `    'reworkAssessmentId' => $reworkAssessment->id,`,
        `]);`,
    ].join('\n');
    const output = execFileSync(
        'php',
        ['artisan', 'tinker', `--execute=${php}`],
        {
            cwd: process.cwd(),
            env: process.env,
            encoding: 'utf8',
        },
    );
    const encodedResult = output.match(/PHASE8_REWORK:(\{.*\})/)?.[1];

    if (encodedResult === undefined) {
        throw new Error('The deterministic rework fixture returned no result.');
    }

    return JSON.parse(encodedResult) as ReworkResult;
}

async function login(page: Page, fixture: QaBrowserFixture): Promise<void> {
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.getByLabel('Email address').fill(fixture.email);
    await page.getByLabel('Password', { exact: true }).fill(fixture.password);
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await page.waitForURL(/\/dashboard$/);
}

async function openAssessment(
    page: Page,
    fixture: QaBrowserFixture,
): Promise<void> {
    await login(page, fixture);
    const response = await page.goto(fixture.path);

    expect(response?.ok()).toBeTruthy();
    await expect(
        page.getByRole('heading', {
            name: 'Simulated merge decision center',
        }),
    ).toBeVisible();
    await expect(page.getByText('Simulated and unverified')).toBeVisible();
}

test('approves a merge-ready low-risk simulated assessment', async ({
    page,
}) => {
    const fixture = provisionAssessment('merge_ready_low_risk');
    await openAssessment(page, fixture);

    await expect(
        page.getByText('develop', { exact: true }).first(),
    ).toBeVisible();
    const reviewStatus = page.getByRole('region', {
        name: 'Scope and review status',
    });

    for (const dimension of ['CI', 'Tests', 'Architecture', 'Security']) {
        await expect(
            reviewStatus.getByText(dimension, { exact: true }),
        ).toBeVisible();
    }

    await expect(reviewStatus.getByText('Passed', { exact: true })).toHaveCount(
        4,
    );
    await page.getByRole('button', { name: 'Approve simulated merge' }).click();
    await page
        .getByRole('button', {
            name: 'Confirm approve simulated merge',
        })
        .click();

    await expect(page.getByText(/terminal human decision/i)).toBeVisible();
    await expect(page.getByText(/Approved For Merge/)).toBeVisible();
    await expect(
        page.getByText(/No repository merge will be performed/),
    ).toBeVisible();
    await page.reload();
    await expect(page.getByText(/terminal human decision/i)).toBeVisible();
    await expect(
        page.getByText(/No repository merge will be performed/),
    ).toBeVisible();
});

test('requests changes with a required reason', async ({ page }) => {
    const fixture = provisionAssessment('merge_ready_low_risk');
    await openAssessment(page, fixture);

    await page.getByRole('button', { name: 'Request changes' }).click();
    await page
        .getByLabel('Reason (required)')
        .fill('Add negative-path and rollback evidence.');
    await page.getByRole('button', { name: 'Confirm request changes' }).click();

    await expect(page.getByText(/Changes Requested/)).toBeVisible();
    await expect(page.getByText(/terminal human decision/i)).toBeVisible();
    await expect(
        page.getByRole('region', { name: 'Assessment summary' }),
    ).toBeVisible();
    await expect(
        page.getByRole('region', { name: 'Evidence references' }),
    ).toBeVisible();

    const rework = triggerDeterministicRework(fixture.projectSlug);

    expect(rework.reworkExecutionId).not.toBe(rework.previousExecutionId);
    expect(rework.reworkAssessmentId).not.toBe(rework.previousAssessmentId);

    await page.reload();
    await expect(page.getByText(/For Qa/i)).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Approve simulated merge' }),
    ).toBeVisible();
});

test('escalates high-risk QA without closing the decision center', async ({
    page,
}) => {
    const fixture = provisionAssessment('merge_ready_high_risk');
    await openAssessment(page, fixture);

    const riskMatrix = page.getByRole('table', {
        name: 'QA impact and merge-risk matrix',
    });
    await expect(
        riskMatrix
            .getByRole('rowheader', { name: 'Regression risk' })
            .locator('..'),
    ).toContainText('High');
    await expect(
        riskMatrix
            .getByRole('rowheader', { name: 'Rollback complexity' })
            .locator('..'),
    ).toContainText('High');
    await page.getByRole('button', { name: 'Escalate for review' }).click();
    await page
        .getByLabel('Reason (required)')
        .fill('Rollback complexity requires release-owner review.');
    await page
        .getByRole('button', { name: 'Confirm escalate for review' })
        .click();

    await expect(page.getByText('Escalate for review')).toBeVisible();
    await expect(page.getByText(/For Qa/i)).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Defer decision' }),
    ).toBeVisible();
});

test('renders expired evidence as stale and never current verified', async ({
    page,
}) => {
    const fixture = provisionAssessment('merge_ready_low_risk', true);
    await openAssessment(page, fixture);

    const evidence = page.getByRole('region', { name: 'Evidence references' });
    await expect(evidence.getByText('Stale', { exact: true })).toBeVisible();
    await expect(evidence.getByText('Expired at')).toBeVisible();
    await expect(evidence.getByText('Verified', { exact: true })).toHaveCount(
        0,
    );
    await expect(page.getByText('Simulated and unverified')).toBeVisible();
});
