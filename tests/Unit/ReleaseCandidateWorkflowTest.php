<?php

declare(strict_types=1);

use App\Domain\Simulation\DeterministicScenario;

test('release workflow checks out and validates the exact develop candidate', function (): void {
    $workflow = releaseCandidateFile('.github/workflows/release-candidate.yml');

    expect($workflow)
        ->toContain('ref: ${{ inputs.candidate_sha }}')
        ->toContain('fetch-depth: 0')
        ->toContain('git rev-parse origin/develop')
        ->toContain('git status --short')
        ->toContain('bin/check-repository-hygiene')
        ->toContain('bin/check-github-actions')
        ->toContain('composer audit --locked')
        ->toContain('php artisan test --testsuite=Concurrency')
        ->toContain('pnpm test:e2e')
        ->toContain('accessibility-critical-flows.spec.ts');
});

test('release policy keeps ordinary CI separate from strict sign off', function (): void {
    $workflow = releaseCandidateFile('.github/workflows/release-candidate.yml');
    $composer = json_decode(
        releaseCandidateFile('composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($workflow)
        ->toContain('security:sign-off')
        ->toContain('--candidate-sha="$CANDIDATE_SHA"')
        ->toContain('ticket_id: "AIOS-294"')
        ->and($composer['scripts']['ci:check'])
        ->not->toContain('@security:sign-off');

    expect($composer['scripts']['repository:check'])
        ->toContain('bash bin/check-release-candidate-policy');
});

test('release workflow preserves failure evidence and protected approvals', function (): void {
    $workflow = releaseCandidateFile('.github/workflows/release-candidate.yml');

    expect($workflow)
        ->toContain('environment: mvp-qa-review')
        ->toContain('environment: mvp-security-review')
        ->toContain('environment: mvp-release-review')
        ->toContain('if: always()')
        ->toContain('retention-days: 90')
        ->toContain('uses: ./.github/workflows/disaster-recovery.yml')
        ->toContain("!= 'dev-jeyelscott'")
        ->toContain('SECURITY_REVIEWER_LOGIN_NORMALIZED')
        ->toContain('QA_OWNER_LOGIN_NORMALIZED')
        ->toContain('RELEASE_MANAGER_LOGIN_NORMALIZED')
        ->toContain('!= "${SECURITY_REVIEWER_LOGIN_NORMALIZED,,}"')
        ->toContain('notion_approval_sha');
});

test('all fourteen scenarios have actual command-result manifest seams', function (): void {
    $runner = releaseCandidateFile('bin/run-release-scenarios');

    foreach (DeterministicScenario::values() as $scenario) {
        expect($runner)->toContain($scenario);
    }

    expect(DeterministicScenario::values())
        ->toHaveCount(14)
        ->and($runner)
        ->toContain('catalog_fingerprint')
        ->toContain('command: $command')
        ->toContain('exit_code: $exit_code')
        ->toContain('candidate_sha: $candidate_sha')
        ->toContain('sha256sum');
});

test('documentation names the Notion roadmap authority and remains blocked', function (): void {
    $readme = releaseCandidateFile('README.md');
    $documentationIndex = releaseCandidateFile('docs/README.md');
    $definitionOfDone = releaseCandidateFile(
        'docs/evidence/mvp-definition-of-done.md',
    );
    $release = releaseCandidateFile('docs/releases/v0.1.0-rc.1.md');

    foreach ([$readme, $documentationIndex] as $authorityDocument) {
        expect($authorityDocument)
            ->toContain('ab04e5c5-cea3-8310-b536-81092834fdba')
            ->toContain('15f4e5c5-cea3-8362-9ce6-07add7b903ab')
            ->toContain('AIOS-294')
            ->not->toContain('ai-operating-system-detailed-build-roadmap-v1.0.md');
    }

    expect($definitionOfDone)
        ->toContain('Decision: Blocked')
        ->toContain('Blocked awaiting independent reviewers')
        ->not->toContain('sharp@0.34.5')
        ->not->toContain('worktree is not committed')
        ->and($release)
        ->toContain('Blocked awaiting independent reviewers');
});

/**
 * Read a release-candidate contract file.
 */
function releaseCandidateFile(string $relativePath): string
{
    $contents = file_get_contents(
        dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$relativePath,
    );

    expect($contents)->toBeString();

    return $contents;
}
