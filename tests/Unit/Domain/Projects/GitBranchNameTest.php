<?php

declare(strict_types=1);

use App\Domain\Projects\Configuration\GitBranchName;

test(
    'valid Git branch names are accepted',
    function (string $branch): void {
        expect(GitBranchName::isValid($branch))->toBeTrue();
    },
)->with([
    'main' => ['main'],
    'develop' => ['develop'],
    'feature branch' => [
        'feature/AIOS-023-repository-metadata',
    ],
    'fix branch' => [
        'fix/rate-limit-regression',
    ],
    'release branch' => [
        'release/v1.2.0',
    ],
    'nested branch' => [
        'feature/projects/repository-metadata',
    ],
    'underscore' => [
        'feature/repository_metadata',
    ],
]);

test(
    'invalid Git branch names are rejected',
    function (string $branch): void {
        expect(GitBranchName::isValid($branch))->toBeFalse();
    },
)->with([
    'empty branch' => [''],
    'surrounding whitespace' => [' develop '],
    'branch containing a space' => ['feature/repository metadata'],
    'leading dash' => ['-develop'],
    'single at character' => ['@'],
    'leading slash' => ['/develop'],
    'trailing slash' => ['develop/'],
    'double slash' => ['feature//repository'],
    'double dot' => ['release/1.0..2.0'],
    'trailing dot' => ['release/1.0.'],
    'dot-prefixed component' => ['feature/.hidden'],
    'lock component' => ['feature/repository.lock'],
    'reflog syntax' => ['feature@{1}'],
    'tilde' => ['feature~1'],
    'caret' => ['feature^1'],
    'colon' => ['feature:repository'],
    'question mark' => ['feature?repository'],
    'asterisk' => ['feature*repository'],
    'opening bracket' => ['feature[repository'],
    'backslash' => ['feature\\repository'],
]);
