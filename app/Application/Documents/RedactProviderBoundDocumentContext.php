<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Documents\Exceptions\ProviderBoundRedactionException;
use App\Support\Security\ProviderBoundRedactionPatterns;
use Illuminate\Support\Facades\Log;

/**
 * Removes credentials and configured sensitive values from outbound context.
 */
final readonly class RedactProviderBoundDocumentContext
{
    public function __construct(
        private ProviderBoundRedactionPatterns $patterns,
    ) {}

    /**
     * Redact all configured patterns atomically.
     *
     * No partially redacted string is returned when configuration validation
     * or any individual PCRE execution fails.
     */
    public function handle(string $content): string
    {
        try {
            $patterns = $this->patterns->all();
        } catch (ProviderBoundRedactionException $exception) {
            $this->reportBlockedRedaction(
                exception: $exception,
                configuredPatternCount: 0,
                completedPatternCount: 0,
                replacementCountBeforeFailure: 0,
            );

            throw $exception;
        }

        $redactedContent = $content;
        $completedPatternCount = 0;
        $replacementCount = 0;

        foreach ($patterns as $pattern) {
            $currentReplacementCount = 0;

            try {
                $redactedContent = $this->replace(
                    patternId: $pattern['id'],
                    expression: $pattern['expression'],
                    content: $redactedContent,
                    replacementCount: $currentReplacementCount,
                );
            } catch (ProviderBoundRedactionException $exception) {
                $this->reportBlockedRedaction(
                    exception: $exception,
                    configuredPatternCount: count($patterns),
                    completedPatternCount: $completedPatternCount,
                    replacementCountBeforeFailure: $replacementCount,
                );

                throw $exception;
            }

            $completedPatternCount++;
            $replacementCount += $currentReplacementCount;
        }

        return $redactedContent;
    }

    /**
     * Execute one replacement while suppressing unsafe native warnings.
     */
    private function replace(
        string $patternId,
        string $expression,
        string $content,
        int &$replacementCount,
    ): string {
        $result = null;
        $pcreErrorCode = PREG_INTERNAL_ERROR;

        set_error_handler(
            static fn (
                int $_severity,
                string $_message,
            ): bool => true,
        );

        try {
            $result = preg_replace(
                pattern: $expression,
                replacement: '[REDACTED]',
                subject: $content,
                limit: -1,
                count: $replacementCount,
            );

            $pcreErrorCode = preg_last_error();
        } finally {
            restore_error_handler();
        }

        if (
            ! is_string($result)
            || $pcreErrorCode !== PREG_NO_ERROR
        ) {
            throw ProviderBoundRedactionException::executionFailed(
                patternId: $patternId,
                pcreErrorCode: $pcreErrorCode,
            );
        }

        return $result;
    }

    /**
     * Log only safe operational metadata.
     */
    private function reportBlockedRedaction(
        ProviderBoundRedactionException $exception,
        int $configuredPatternCount,
        int $completedPatternCount,
        int $replacementCountBeforeFailure,
    ): void {
        Log::critical(
            'document.provider_context_redaction_blocked',
            [
                'error_code' => $exception->errorCode(),
                'pattern_id' => $exception->patternId(),
                'pcre_error_code' => $exception->pcreErrorCode(),
                'configured_pattern_count' => $configuredPatternCount,
                'completed_pattern_count' => $completedPatternCount,
                'replacement_count_before_failure' => $replacementCountBeforeFailure,
            ],
        );
    }
}
