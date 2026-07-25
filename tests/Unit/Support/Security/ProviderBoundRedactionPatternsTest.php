<?php

declare(strict_types=1);

use App\Application\Documents\Exceptions\ProviderBoundRedactionException;
use App\Support\Security\ProviderBoundRedactionPatterns;
use Illuminate\Config\Repository;

test('valid built-in and custom redaction patterns are returned in order', function (): void {
    $configuration = new Repository([
        'document-context-redaction' => [
            'corpus_version' => 1,
            'patterns' => [
                [
                    'id' => 'built_in_secret',
                    'expression' => '/\bSECRET-[A-Z0-9]+\b/',
                ],
            ],
            'custom_patterns' => [
                [
                    'id' => 'organization_secret',
                    'expression' => '/\bORG-[A-Z0-9]+\b/',
                ],
            ],
        ],
    ]);

    $patterns = new ProviderBoundRedactionPatterns($configuration);

    expect($patterns->all())->toBe([
        [
            'id' => 'built_in_secret',
            'expression' => '/\bSECRET-[A-Z0-9]+\b/',
        ],
        [
            'id' => 'organization_secret',
            'expression' => '/\bORG-[A-Z0-9]+\b/',
        ],
    ]);
});

test(
    'invalid redaction configuration fails closed',
    function (
        array $redactionConfiguration,
        string $expectedPatternId,
    ): void {
        $configuration = new Repository([
            'document-context-redaction' => $redactionConfiguration,
        ]);

        $patterns = new ProviderBoundRedactionPatterns($configuration);

        try {
            $patterns->validate();

            $this->fail(
                'Expected provider-bound redaction validation to fail.',
            );
        } catch (ProviderBoundRedactionException $exception) {
            expect($exception->errorCode())
                ->toBe(
                    ProviderBoundRedactionException::INVALID_CONFIGURATION,
                )
                ->and($exception->patternId())
                ->toBe($expectedPatternId)
                ->and($exception->getMessage())
                ->not->toContain('unterminated');
        }
    },
)->with([
    'missing corpus version' => [
        [
            'patterns' => [
                [
                    'id' => 'valid_pattern',
                    'expression' => '/secret/',
                ],
            ],
            'custom_patterns' => [],
        ],
        'corpus_version',
    ],
    'empty pattern corpus' => [
        [
            'corpus_version' => 1,
            'patterns' => [],
            'custom_patterns' => [],
        ],
        'patterns',
    ],
    'non-array pattern entry' => [
        [
            'corpus_version' => 1,
            'patterns' => [
                'not-an-array',
            ],
            'custom_patterns' => [],
        ],
        'pattern_0',
    ],
    'invalid pattern identifier' => [
        [
            'corpus_version' => 1,
            'patterns' => [
                [
                    'id' => 'Invalid Pattern ID',
                    'expression' => '/secret/',
                ],
            ],
            'custom_patterns' => [],
        ],
        'pattern_0',
    ],
    'duplicate pattern identifier' => [
        [
            'corpus_version' => 1,
            'patterns' => [
                [
                    'id' => 'duplicate_pattern',
                    'expression' => '/first/',
                ],
                [
                    'id' => 'duplicate_pattern',
                    'expression' => '/second/',
                ],
            ],
            'custom_patterns' => [],
        ],
        'duplicate_pattern',
    ],
    'invalid regular expression' => [
        [
            'corpus_version' => 1,
            'patterns' => [
                [
                    'id' => 'invalid_regex',
                    'expression' => '/[unterminated/',
                ],
            ],
            'custom_patterns' => [],
        ],
        'invalid_regex',
    ],
]);
