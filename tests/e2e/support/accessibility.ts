import AxeBuilder from '@axe-core/playwright';
import { expect } from '@playwright/test';
import type { Page, TestInfo } from '@playwright/test';

const WCAG_TAGS = [
    'wcag2a',
    'wcag2aa',
    'wcag21a',
    'wcag21aa',
    'wcag22aa',
] as const;

type AxeViolation = Awaited<
    ReturnType<AxeBuilder['analyze']>
>['violations'][number];

/**
 * Run the WCAG 2.0, 2.1, and 2.2 Level A/AA axe rules against the page's
 * current interactive state.
 */
export async function expectNoAccessibilityViolations(
    page: Page,
    testInfo: TestInfo,
    evidenceName: string,
) {
    const results = await new AxeBuilder({
        page,
    })
        .withTags([...WCAG_TAGS])
        .analyze();

    const evidence = {
        url: page.url(),
        testEngine: results.testEngine,
        testEnvironment: results.testEnvironment,
        violations: results.violations.map((violation) => ({
            id: violation.id,
            impact: violation.impact,
            help: violation.help,
            helpUrl: violation.helpUrl,
            targets: violation.nodes.map((node) => node.target),
            failureSummaries: violation.nodes.map(
                (node) => node.failureSummary ?? null,
            ),
        })),
        incomplete: results.incomplete.map((result) => ({
            id: result.id,
            impact: result.impact,
            help: result.help,
            targets: result.nodes.map((node) => node.target),
        })),
    };

    await testInfo.attach(`${slugify(evidenceName)}-axe-results`, {
        body: JSON.stringify(evidence, null, 2),
        contentType: 'application/json',
    });

    expect(results.violations, formatViolations(results.violations)).toEqual(
        [],
    );
}

/**
 * Format axe failures into a concise Playwright assertion message.
 */
function formatViolations(violations: AxeViolation[]): string {
    if (violations.length === 0) {
        return 'No automatically detectable accessibility violations.';
    }

    return violations
        .map((violation) => {
            const targets = violation.nodes
                .flatMap((node) => node.target)
                .join(', ');

            return [
                `${violation.id} (${violation.impact ?? 'unknown impact'})`,
                violation.help,
                targets,
                violation.helpUrl,
            ].join('\n');
        })
        .join('\n\n');
}

/**
 * Convert an evidence title into a stable attachment name.
 */
function slugify(value: string): string {
    return value
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}
