<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance;

use App\Application\QualityAssurance\Data\MergeRisk;
use App\Application\QualityAssurance\Data\QaAssessmentResult;
use App\Application\QualityAssurance\Data\QaFinding;
use Illuminate\Support\Str;

/**
 * Validates structural safety, canonical serialization, and evidence references.
 */
final class QaAssessmentValidator
{
    public function __construct(
        private readonly BlockingFindingPolicy $blockingFindings = new BlockingFindingPolicy,
    ) {}

    /**
     * Validate one complete QA assessment contract before it is persisted or used.
     */
    public function validateAssessment(
        QaAssessmentResult $assessment,
    ): void {
        if (
            $assessment->schemaVersion
            !== QaAssessmentResult::SCHEMA_VERSION
        ) {
            throw new \InvalidArgumentException(
                'QA assessment schema version is unsupported.',
            );
        }

        if (
            $assessment->confidence < 0
            || $assessment->confidence > 1
            || ! is_finite($assessment->confidence)
        ) {
            throw new \InvalidArgumentException(
                'QA assessment confidence is invalid.',
            );
        }

        if ($assessment->targetBranch !== 'develop') {
            throw new \InvalidArgumentException(
                'QA assessment target branch must be develop.',
            );
        }

        $this->text(
            $assessment->recommendation,
            'recommendation',
            4_000,
        );

        $assessmentEvidence = $this->validateEvidenceIds(
            $assessment->evidenceIds,
            'assessment evidence',
        );

        $this->validateFindings(
            $assessment->unresolvedFindings,
            $assessmentEvidence,
        );

        $this->validateMergeRisks(
            $assessment->mergeRisks,
            $assessmentEvidence,
        );

        $this->rejectSecrets($assessment->toArray(false));
        $this->boundedPayload($assessment->toArray(false));

        if (
            preg_match(
                '/\A[0-9a-f]{64}\z/',
                $assessment->canonicalAssessmentFingerprint,
            ) !== 1
            || ! hash_equals(
                $this->fingerprint($assessment),
                $assessment->canonicalAssessmentFingerprint,
            )
        ) {
            throw new \InvalidArgumentException(
                'QA assessment fingerprint does not match canonical content.',
            );
        }

        $this->blockingFindings->assertRecommendationAllowed(
            $assessment,
        );
    }

    /**
     * Produce the stable SHA-256 fingerprint for an assessment without its fingerprint field.
     */
    public function fingerprint(
        QaAssessmentResult $assessment,
    ): string {
        return hash(
            'sha256',
            $this->canonicalJson($assessment->toArray(false)),
        );
    }

    /**
     * Serialize mixed contract data with recursively sorted associative keys.
     */
    public function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Verify that a provider payload already uses the canonical JSON representation.
     */
    public function validateCanonicalPayload(string $payload): void
    {
        $decoded = json_decode(
            $payload,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        if ($payload !== $this->canonicalJson($decoded)) {
            throw new \InvalidArgumentException(
                'QA assessment payload serialization is not canonical.',
            );
        }
    }

    /**
     * Canonicalize nested arrays while preserving semantically ordered lists.
     */
    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $canonical = array_map(
            $this->canonicalize(...),
            $value,
        );

        if (! array_is_list($canonical)) {
            ksort($canonical);
        }

        return $canonical;
    }

    /**
     * Validate unresolved findings, duplicate codes, and referenced evidence.
     *
     * @param  list<QaFinding>  $findings
     * @param  array<string, true>  $assessmentEvidence
     */
    private function validateFindings(
        array $findings,
        array $assessmentEvidence,
    ): void {
        if (count($findings) > 100) {
            throw new \InvalidArgumentException(
                'QA assessment findings exceed the item limit.',
            );
        }

        $codes = [];

        foreach ($findings as $finding) {
            $this->text(
                $finding->code,
                'finding code',
                100,
            );
            $this->text(
                $finding->summary,
                'finding summary',
                2_000,
            );
            $this->text(
                $finding->impact,
                'finding impact',
                4_000,
            );
            $this->text(
                $finding->mitigation,
                'finding mitigation',
                4_000,
            );

            if (isset($codes[$finding->code])) {
                throw new \InvalidArgumentException(
                    'QA assessment finding codes must be unique.',
                );
            }

            $codes[$finding->code] = true;

            $this->validateNestedEvidence(
                $finding->evidenceIds,
                $assessmentEvidence,
                'finding evidence',
            );
        }
    }

    /**
     * Validate merge risks, duplicate codes, and referenced evidence.
     *
     * @param  list<MergeRisk>  $risks
     * @param  array<string, true>  $assessmentEvidence
     */
    private function validateMergeRisks(
        array $risks,
        array $assessmentEvidence,
    ): void {
        if (count($risks) > 100) {
            throw new \InvalidArgumentException(
                'QA assessment merge risks exceed the item limit.',
            );
        }

        $codes = [];

        foreach ($risks as $risk) {
            $this->text(
                $risk->code,
                'merge-risk code',
                100,
            );
            $this->text(
                $risk->summary,
                'merge-risk summary',
                2_000,
            );
            $this->text(
                $risk->impact,
                'merge-risk impact',
                4_000,
            );
            $this->text(
                $risk->mitigation,
                'merge-risk mitigation',
                4_000,
            );

            if (isset($codes[$risk->code])) {
                throw new \InvalidArgumentException(
                    'QA assessment merge-risk codes must be unique.',
                );
            }

            $codes[$risk->code] = true;

            $this->validateNestedEvidence(
                $risk->evidenceIds,
                $assessmentEvidence,
                'merge-risk evidence',
            );
        }
    }

    /**
     * Validate top-level evidence identifiers and return them as a lookup set.
     *
     * @param  list<string>  $evidenceIds
     * @return array<string, true>
     */
    private function validateEvidenceIds(
        array $evidenceIds,
        string $name,
    ): array {
        if (count($evidenceIds) > 100) {
            throw new \InvalidArgumentException(
                "QA assessment {$name} exceeds the item limit.",
            );
        }

        $validated = [];

        foreach ($evidenceIds as $evidenceId) {
            if (! Str::isUlid($evidenceId)) {
                throw new \InvalidArgumentException(
                    "QA assessment {$name} contains an invalid ULID.",
                );
            }

            if (isset($validated[$evidenceId])) {
                throw new \InvalidArgumentException(
                    "QA assessment {$name} contains a duplicate ULID.",
                );
            }

            $validated[$evidenceId] = true;
        }

        return $validated;
    }

    /**
     * Ensure nested evidence references are valid and declared by the assessment.
     *
     * @param  list<string>  $evidenceIds
     * @param  array<string, true>  $assessmentEvidence
     */
    private function validateNestedEvidence(
        array $evidenceIds,
        array $assessmentEvidence,
        string $name,
    ): void {
        $validated = $this->validateEvidenceIds(
            $evidenceIds,
            $name,
        );

        foreach (array_keys($validated) as $evidenceId) {
            if (! isset($assessmentEvidence[$evidenceId])) {
                throw new \InvalidArgumentException(
                    "QA assessment {$name} must be declared by the assessment.",
                );
            }
        }
    }

    /**
     * Validate trimmed, non-empty text against its contract-specific maximum length.
     */
    private function text(
        string $value,
        string $name,
        int $maximum,
    ): void {
        if (
            $value === ''
            || trim($value) !== $value
            || strlen($value) > $maximum
        ) {
            throw new \InvalidArgumentException(
                "QA assessment {$name} is invalid.",
            );
        }
    }

    /**
     * Reject common unredacted credential shapes before contract persistence.
     */
    private function rejectSecrets(mixed $value): void
    {
        $serialized = json_encode(
            $value,
            JSON_THROW_ON_ERROR,
        );

        if (
            preg_match(
                '/(?:sk-[A-Za-z0-9]{12,}|gh[pousr]_[A-Za-z0-9]{12,}|password\s*[=:]|api[_-]?key\s*[=:]|private[_-]?key)/i',
                $serialized,
            ) === 1
        ) {
            throw new \InvalidArgumentException(
                'QA assessment contains an unredacted secret.',
            );
        }
    }

    /**
     * Reject contracts larger than the established 256 KiB provider payload limit.
     *
     * @param  array<string, mixed>  $payload
     */
    private function boundedPayload(array $payload): void
    {
        if (
            strlen(
                json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR,
                ),
            ) > 262_144
        ) {
            throw new \InvalidArgumentException(
                'QA assessment exceeds the maximum payload size.',
            );
        }
    }
}
