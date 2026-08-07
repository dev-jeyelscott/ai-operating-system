<?php

declare(strict_types=1);

use App\Application\Development\RepositoryExecutionPolicy;
use App\Domain\Development\RepositoryPolicyFailureReason;

test('ticket types map to deterministic synthetic branch prefixes', function (string $type, string $prefix): void {
    $branch = (new RepositoryExecutionPolicy)->sourceBranch($type, 'AIOS-095', 'Branch & PR Policy');

    expect($branch)->toBe("{$prefix}/aios-095-branch-pr-policy");
})->with([
    ['Feature', 'feature'], ['Bug', 'fix'], ['Enhancement', 'enhancement'], ['Change', 'change'],
    ['Security', 'chore'], ['Documentation', 'chore'], ['Infrastructure', 'chore'],
    ['Investigation', 'chore'], ['Technical debt', 'chore'],
]);

test('branch normalization is unicode safe bounded and deterministic', function (): void {
    $policy = new RepositoryExecutionPolicy;
    $title = '  Café — repeated!!! separators '.str_repeat('long ', 80);
    $first = $policy->sourceBranch('Feature', 'AIOS-Ü95', $title);
    $second = $policy->sourceBranch('feature', 'AIOS-Ü95', $title);

    expect($first)->toBe($second)
        ->toStartWith('feature/aios-u95-cafe-repeated-separators-')
        ->and(strlen($first))->toBeLessThanOrEqual(120)
        ->and($first)->not->toContain('--');
});

test('approved custom prefix is used without changing deterministic normalization', function (): void {
    expect((new RepositoryExecutionPolicy)->sourceBranch('Feature', 'AIOS-095', 'Policy', 'product'))
        ->toBe('product/aios-095-policy');
});

test('pull request target policy returns stable reason codes', function (string $source, string $target, bool $allowed, ?RepositoryPolicyFailureReason $reason): void {
    $decision = (new RepositoryExecutionPolicy)->validatePullRequest($source, $target);

    expect($decision->allowed)->toBe($allowed)->and($decision->reason)->toBe($reason);
})->with([
    'develop accepted' => ['feature/aios-095-policy', 'develop', true, null],
    'main rejected' => ['feature/aios-095-policy', 'main', false, RepositoryPolicyFailureReason::MainTarget],
    'other rejected' => ['feature/aios-095-policy', 'release', false, RepositoryPolicyFailureReason::UnapprovedTarget],
    'blank rejected' => ['feature/aios-095-policy', '', false, RepositoryPolicyFailureReason::BlankTarget],
    'protected source' => ['main', 'develop', false, RepositoryPolicyFailureReason::ProtectedSource],
    'source equals target' => ['develop', 'develop', false, RepositoryPolicyFailureReason::SourceEqualsTarget],
    'invalid source' => ['bad branch', 'develop', false, RepositoryPolicyFailureReason::InvalidSourceBranch],
]);

test('direct pushes to protected branches are rejected', function (): void {
    $policy = new RepositoryExecutionPolicy;

    expect($policy->validateDirectPush('develop')->reason)->toBe(RepositoryPolicyFailureReason::DirectPushProtected)
        ->and($policy->validateDirectPush('feature/aios-095')->allowed)->toBeTrue();
});

test('blank normalized branch inputs fail before any artifact exists', function (): void {
    expect(fn () => (new RepositoryExecutionPolicy)->sourceBranch('Feature', '---', '...'))
        ->toThrow(InvalidArgumentException::class, RepositoryPolicyFailureReason::InvalidSourceBranch->value);
});
