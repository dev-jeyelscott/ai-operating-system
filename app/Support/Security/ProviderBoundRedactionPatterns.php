<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Application\Documents\Exceptions\ProviderBoundRedactionException;
use Illuminate\Contracts\Config\Repository;

final readonly class ProviderBoundRedactionPatterns
{
    private const PATTERN_ID_EXPRESSION =
        '/\A[a-z0-9][a-z0-9._-]{0,63}\z/D';

    public function __construct(
        private Repository $config,
    ) {}

    /**
     * Validate the complete configured pattern corpus.
     */
    public function validate(): void
    {
        $this->all();
    }

    /**
     * Return every validated built-in and custom redaction pattern.
     *
     * @return list<array{
     *     id: string,
     *     expression: string
     * }>
     */
    public function all(): array
    {
        $corpusVersion = $this->config->get(
            'document-context-redaction.corpus_version',
        );

        if (! is_int($corpusVersion) || $corpusVersion < 1) {
            throw ProviderBoundRedactionException::invalidConfiguration(
                'corpus_version',
            );
        }

        $builtInPatterns = $this->config->get(
            'document-context-redaction.patterns',
        );

        $customPatterns = $this->config->get(
            'document-context-redaction.custom_patterns',
            [],
        );

        if (
            ! is_array($builtInPatterns)
            || ! array_is_list($builtInPatterns)
        ) {
            throw ProviderBoundRedactionException::invalidConfiguration(
                'patterns',
            );
        }

        if (
            ! is_array($customPatterns)
            || ! array_is_list($customPatterns)
        ) {
            throw ProviderBoundRedactionException::invalidConfiguration(
                'custom_patterns',
            );
        }

        $configuredPatterns = [
            ...$builtInPatterns,
            ...$customPatterns,
        ];

        if ($configuredPatterns === []) {
            throw ProviderBoundRedactionException::invalidConfiguration(
                'patterns',
            );
        }

        /** @var array<string, true> $seenPatternIds */
        $seenPatternIds = [];

        /** @var list<array{id: string, expression: string}> $validated */
        $validated = [];

        foreach ($configuredPatterns as $index => $configuredPattern) {
            $fallbackPatternId = sprintf('pattern_%d', $index);

            if (! is_array($configuredPattern)) {
                throw ProviderBoundRedactionException::invalidConfiguration(
                    $fallbackPatternId,
                );
            }

            $patternId = $configuredPattern['id'] ?? null;
            $expression = $configuredPattern['expression'] ?? null;

            if (
                ! is_string($patternId)
                || preg_match(
                    self::PATTERN_ID_EXPRESSION,
                    $patternId,
                ) !== 1
            ) {
                throw ProviderBoundRedactionException::invalidConfiguration(
                    $fallbackPatternId,
                );
            }

            if (isset($seenPatternIds[$patternId])) {
                throw ProviderBoundRedactionException::invalidConfiguration(
                    $patternId,
                );
            }

            if (
                ! is_string($expression)
                || trim($expression) === ''
            ) {
                throw ProviderBoundRedactionException::invalidConfiguration(
                    $patternId,
                );
            }

            $this->assertCompiles(
                patternId: $patternId,
                expression: $expression,
            );

            $seenPatternIds[$patternId] = true;

            $validated[] = [
                'id' => $patternId,
                'expression' => $expression,
            ];
        }

        return $validated;
    }

    /**
     * Compile one expression without allowing PHP warnings to leak it.
     */
    private function assertCompiles(
        string $patternId,
        string $expression,
    ): void {
        $result = false;
        $pcreErrorCode = PREG_INTERNAL_ERROR;

        set_error_handler(
            static fn (
                int $_severity,
                string $_message,
            ): bool => true,
        );

        try {
            $result = preg_match(
                $expression,
                'redaction-validation-probe',
            );

            $pcreErrorCode = preg_last_error();
        } finally {
            restore_error_handler();
        }

        if (
            $result === false
            || $pcreErrorCode !== PREG_NO_ERROR
        ) {
            throw ProviderBoundRedactionException::invalidConfiguration(
                patternId: $patternId,
                pcreErrorCode: $pcreErrorCode,
            );
        }
    }
}
