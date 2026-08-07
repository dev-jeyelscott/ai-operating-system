<?php

declare(strict_types=1);

namespace App\Infrastructure\Documents;

use App\Application\Documents\Contracts\DocumentAnalyzer;
use App\Application\Documents\Data\DocumentAnalysis;
use App\Domain\Documents\DocumentClassification;
use App\Models\DocumentVersion;

final class DeterministicDocumentAnalyzer implements DocumentAnalyzer
{
    private const NAME = 'deterministic-document-analyzer';

    private const VERSION = '1.0.0';

    /**
     * Return the stable analyzer identifier.
     */
    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Return the deterministic analyzer ruleset version.
     */
    public function version(): string
    {
        return self::VERSION;
    }

    /**
     * Produce repeatable classification, safety, conflict, and gap results.
     */
    public function analyze(
        DocumentVersion $version,
        int $seed,
    ): DocumentAnalysis {
        $content = (string) $version->parsed_content;
        $normalizedContent = strtolower($content);

        $classification = str_contains(
            $normalizedContent,
            'architecture',
        )
            ? DocumentClassification::Architecture
            : DocumentClassification::Specification;

        $flags = [];

        if (
            str_contains(
                $normalizedContent,
                'ignore previous instructions',
            )
            || str_contains($normalizedContent, 'system prompt')
            || str_contains($normalizedContent, 'jailbreak')
        ) {
            $flags[] = 'prompt_injection';
        }

        if (
            str_contains($normalizedContent, 'bypass security')
            || str_contains($normalizedContent, 'exfiltrate')
            || str_contains($normalizedContent, 'disable safeguards')
        ) {
            $flags[] = 'unsafe_instruction';
        }

        return new DocumentAnalysis(
            summary: sprintf(
                'Deterministic summary #%d: %s',
                $seed,
                substr(trim($content), 0, 120),
            ),
            classification: $classification,
            conflicts: str_contains($content, '[conflict]')
                ? ['Conflicting instruction detected.']
                : [],
            gaps: str_contains($content, '[gap]')
                ? ['Required detail is missing.']
                : [],
            flags: $flags,
        );
    }
}
