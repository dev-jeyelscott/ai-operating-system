<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance\Data;

use App\Domain\QualityAssurance\QaDecision;
use App\Domain\QualityAssurance\QaImpactLevel;
use App\Domain\QualityAssurance\QaReviewStatus;

/**
 * Defines the canonical, versioned Layer 3 QA and merge-risk result contract.
 */
final readonly class QaAssessmentResult
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  list<QaFinding>  $unresolvedFindings
     * @param  list<MergeRisk>  $mergeRisks
     * @param  list<string>  $evidenceIds
     */
    public function __construct(
        public QaDecision $decision,
        public float $confidence,
        public string $targetBranch,
        public bool $ticketScopeSatisfied,
        public bool $acceptanceCriteriaVerified,
        public QaReviewStatus $ciStatus,
        public QaReviewStatus $testStatus,
        public QaReviewStatus $architectureStatus,
        public QaReviewStatus $securityStatus,
        public QaImpactLevel $databaseImpact,
        public QaImpactLevel $performanceImpact,
        public QaImpactLevel $regressionRisk,
        public QaImpactLevel $rollbackComplexity,
        public array $unresolvedFindings,
        public array $mergeRisks,
        public string $recommendation,
        public array $evidenceIds,
        public string $canonicalAssessmentFingerprint,
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {}

    /**
     * Convert the assessment to its canonical transport representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $includeFingerprint = true): array
    {
        $data = [
            'schema_version' => $this->schemaVersion,
            'decision' => $this->decision->value,
            'confidence' => $this->confidence,
            'target_branch' => $this->targetBranch,
            'ticket_scope_satisfied' => $this->ticketScopeSatisfied,
            'acceptance_criteria_verified' => $this->acceptanceCriteriaVerified,
            'ci_status' => $this->ciStatus->value,
            'test_status' => $this->testStatus->value,
            'architecture_status' => $this->architectureStatus->value,
            'security_status' => $this->securityStatus->value,
            'database_impact' => $this->databaseImpact->value,
            'performance_impact' => $this->performanceImpact->value,
            'regression_risk' => $this->regressionRisk->value,
            'rollback_complexity' => $this->rollbackComplexity->value,
            'unresolved_findings' => array_map(
                static fn (QaFinding $finding): array => $finding->toArray(),
                $this->unresolvedFindings,
            ),
            'merge_risks' => array_map(
                static fn (MergeRisk $risk): array => $risk->toArray(),
                $this->mergeRisks,
            ),
            'recommendation' => $this->recommendation,
            'evidence_ids' => $this->evidenceIds,
        ];

        if ($includeFingerprint) {
            $data['canonical_assessment_fingerprint'] =
                $this->canonicalAssessmentFingerprint;
        }

        return $data;
    }

    /**
     * Build an assessment from a strictly typed transport payload.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            decision: QaDecision::from(
                self::string($data, 'decision'),
            ),
            confidence: self::float($data, 'confidence'),
            targetBranch: self::string($data, 'target_branch'),
            ticketScopeSatisfied: self::boolean(
                $data,
                'ticket_scope_satisfied',
            ),
            acceptanceCriteriaVerified: self::boolean(
                $data,
                'acceptance_criteria_verified',
            ),
            ciStatus: QaReviewStatus::from(
                self::string($data, 'ci_status'),
            ),
            testStatus: QaReviewStatus::from(
                self::string($data, 'test_status'),
            ),
            architectureStatus: QaReviewStatus::from(
                self::string($data, 'architecture_status'),
            ),
            securityStatus: QaReviewStatus::from(
                self::string($data, 'security_status'),
            ),
            databaseImpact: QaImpactLevel::from(
                self::string($data, 'database_impact'),
            ),
            performanceImpact: QaImpactLevel::from(
                self::string($data, 'performance_impact'),
            ),
            regressionRisk: QaImpactLevel::from(
                self::string($data, 'regression_risk'),
            ),
            rollbackComplexity: QaImpactLevel::from(
                self::string($data, 'rollback_complexity'),
            ),
            unresolvedFindings: self::objects(
                $data['unresolved_findings'] ?? null,
                QaFinding::fromArray(...),
            ),
            mergeRisks: self::objects(
                $data['merge_risks'] ?? null,
                MergeRisk::fromArray(...),
            ),
            recommendation: self::string($data, 'recommendation'),
            evidenceIds: self::strings($data['evidence_ids'] ?? null),
            canonicalAssessmentFingerprint: self::string(
                $data,
                'canonical_assessment_fingerprint',
            ),
            schemaVersion: self::integer($data, 'schema_version'),
        );
    }

    /**
     * Read one required string without silently coercing malformed values.
     *
     * @param  array<string, mixed>  $data
     */
    private static function string(array $data, string $key): string
    {
        if (
            ! array_key_exists($key, $data)
            || ! is_string($data[$key])
        ) {
            throw new \InvalidArgumentException(
                "QA assessment {$key} field is malformed.",
            );
        }

        return $data[$key];
    }

    /**
     * Read one required integer without accepting numeric strings.
     *
     * @param  array<string, mixed>  $data
     */
    private static function integer(array $data, string $key): int
    {
        if (
            ! array_key_exists($key, $data)
            || ! is_int($data[$key])
        ) {
            throw new \InvalidArgumentException(
                "QA assessment {$key} field is malformed.",
            );
        }

        return $data[$key];
    }

    /**
     * Read one required floating-point number without accepting numeric strings.
     *
     * @param  array<string, mixed>  $data
     */
    private static function float(array $data, string $key): float
    {
        if (
            ! array_key_exists($key, $data)
            || (
                ! is_float($data[$key])
                && ! is_int($data[$key])
            )
        ) {
            throw new \InvalidArgumentException(
                "QA assessment {$key} field is malformed.",
            );
        }

        return (float) $data[$key];
    }

    /**
     * Read one required boolean without accepting integer or string substitutes.
     *
     * @param  array<string, mixed>  $data
     */
    private static function boolean(array $data, string $key): bool
    {
        if (
            ! array_key_exists($key, $data)
            || ! is_bool($data[$key])
        ) {
            throw new \InvalidArgumentException(
                "QA assessment {$key} field is malformed.",
            );
        }

        return $data[$key];
    }

    /**
     * Read one required list of strings without discarding malformed entries.
     *
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (
            ! is_array($value)
            || ! array_is_list($value)
            || array_any(
                $value,
                static fn (mixed $item): bool => ! is_string($item),
            )
        ) {
            throw new \InvalidArgumentException(
                'QA assessment string list is malformed.',
            );
        }

        return $value;
    }

    /**
     * Read one required list of structured objects using the supplied factory.
     *
     * @return list<mixed>
     */
    private static function objects(
        mixed $value,
        callable $factory,
    ): array {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new \InvalidArgumentException(
                'QA assessment object list is malformed.',
            );
        }

        return array_map(
            static fn (mixed $item): mixed => $factory(
                self::object($item),
            ),
            $value,
        );
    }

    /**
     * Read one required associative object from a nested payload.
     *
     * @return array<string, mixed>
     */
    private static function object(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException(
                'QA assessment nested object is malformed.',
            );
        }

        return $value;
    }
}
