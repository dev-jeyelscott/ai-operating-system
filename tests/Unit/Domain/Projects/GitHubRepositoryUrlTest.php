<?php

declare(strict_types=1);

use App\Domain\Projects\Configuration\GitHubRepositoryUrl;

test(
    'valid GitHub repository URLs are accepted',
    function (string $url): void {
        expect(GitHubRepositoryUrl::isValid($url))->toBeTrue();
    },
)->with([
    'repository page URL' => [
        'https://github.com/example/example-repository',
    ],
    'HTTPS clone URL' => [
        'https://github.com/example/example-repository.git',
    ],
    'trailing slash' => [
        'https://github.com/example/example-repository/',
    ],
    'mixed-case repository name' => [
        'https://github.com/Example/Example.Repository',
    ],
    'hyphenated organization' => [
        'https://github.com/example-organization/example_repository',
    ],
]);

test(
    'invalid or unsafe GitHub repository URLs are rejected',
    function (string $url): void {
        expect(GitHubRepositoryUrl::isValid($url))->toBeFalse();
    },
)->with([
    'empty URL' => [''],
    'surrounding whitespace' => [
        ' https://github.com/example/repository ',
    ],
    'HTTP URL' => [
        'http://github.com/example/repository',
    ],
    'different provider' => [
        'https://gitlab.com/example/repository',
    ],
    'embedded username' => [
        'https://user@github.com/example/repository',
    ],
    'embedded credentials' => [
        'https://user:token@github.com/example/repository',
    ],
    'custom port' => [
        'https://github.com:443/example/repository',
    ],
    'query string' => [
        'https://github.com/example/repository?token=secret',
    ],
    'fragment' => [
        'https://github.com/example/repository#readme',
    ],
    'repository issue URL' => [
        'https://github.com/example/repository/issues',
    ],
    'missing repository' => [
        'https://github.com/example',
    ],
    'encoded path value' => [
        'https://github.com/example%2Frepository/another',
    ],
    'empty repository after git suffix' => [
        'https://github.com/example/.git',
    ],
]);
