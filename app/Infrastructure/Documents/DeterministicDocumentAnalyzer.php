<?php

declare(strict_types=1);

namespace App\Infrastructure\Documents;

use App\Application\Documents\Contracts\DocumentAnalyzer;
use App\Application\Documents\Data\DocumentAnalysis;
use App\Domain\Documents\DocumentClassification;
use App\Models\DocumentVersion;

final class DeterministicDocumentAnalyzer implements DocumentAnalyzer
{
    public function analyze(DocumentVersion $version, int $seed): DocumentAnalysis
    {
        $content = (string) $version->parsed_content;
        $classification = str_contains(strtolower($content), 'architecture') ? DocumentClassification::Architecture : DocumentClassification::Specification;

        $normalizedContent = strtolower($content);
        $flags = [];

        if (
            str_contains($normalizedContent, 'ignore previous instructions')
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
            "Deterministic summary #{$seed}: ".substr(trim($content), 0, 120),
            $classification,
            str_contains($content, '[conflict]') ? ['Conflicting instruction detected.'] : [],
            str_contains($content, '[gap]') ? ['Required detail is missing.'] : [],
            $flags,
        );
    }
}
