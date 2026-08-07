<?php

declare(strict_types=1);

namespace App\Application\Development\Data;

use App\Domain\Development\DevelopmentExecutionOutcome;
use App\Domain\Development\DevelopmentFailureClassification;
use App\Domain\Development\DevelopmentSimulationClassification;
use App\Domain\Development\DevelopmentVerificationClassification;

final readonly class DevelopmentExecutionResult
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  list<DevelopmentStageResult>  $stageResults
     * @param  list<string>  $implementationPlan
     * @param  list<DevelopmentChangedFile>  $changedFiles
     * @param  list<DevelopmentValidationResult>  $validationResults
     * @param  list<string>  $assumptions
     * @param  list<string>  $risks
     * @param  list<string>  $evidenceGaps
     */
    public function __construct(
        public string $providerIdentifier,
        public string $capability,
        public DevelopmentExecutionOutcome $outcome,
        public array $stageResults,
        public array $implementationPlan,
        public array $changedFiles,
        public string $diffSummary,
        public array $validationResults,
        public DevelopmentRepositoryArtifact $syntheticBranchResult,
        public ?DevelopmentRepositoryArtifact $syntheticCommitResult,
        public ?DevelopmentRepositoryArtifact $syntheticPushResult,
        public ?DevelopmentRepositoryArtifact $syntheticPullRequestResult,
        public string $targetBranch,
        public array $assumptions,
        public float $confidence,
        public array $risks,
        public array $evidenceGaps,
        public DevelopmentSimulationClassification $simulationClassification,
        public DevelopmentVerificationClassification $verificationClassification,
        public DevelopmentFailureClassification $retryClassification,
        public string $recommendedNextAction,
        public string $canonicalResultFingerprint,
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(bool $includeFingerprint = true): array
    {
        $data = [
            'schema_version' => $this->schemaVersion, 'provider_identifier' => $this->providerIdentifier,
            'capability' => $this->capability, 'outcome' => $this->outcome->value,
            'stage_results' => array_map(static fn (DevelopmentStageResult $value): array => $value->toArray(), $this->stageResults),
            'implementation_plan' => $this->implementationPlan,
            'changed_files' => array_map(static fn (DevelopmentChangedFile $value): array => $value->toArray(), $this->changedFiles),
            'diff_summary' => $this->diffSummary,
            'validation_results' => array_map(static fn (DevelopmentValidationResult $value): array => $value->toArray(), $this->validationResults),
            'synthetic_branch_result' => $this->syntheticBranchResult->toArray(),
            'synthetic_commit_result' => $this->syntheticCommitResult?->toArray(),
            'synthetic_push_result' => $this->syntheticPushResult?->toArray(),
            'synthetic_pull_request_result' => $this->syntheticPullRequestResult?->toArray(),
            'target_branch' => $this->targetBranch, 'assumptions' => $this->assumptions, 'confidence' => $this->confidence,
            'risks' => $this->risks, 'evidence_gaps' => $this->evidenceGaps,
            'simulation_classification' => $this->simulationClassification->value,
            'verification_classification' => $this->verificationClassification->value,
            'retry_classification' => $this->retryClassification->value,
            'recommended_next_action' => $this->recommendedNextAction,
        ];

        if ($includeFingerprint) {
            $data['canonical_result_fingerprint'] = $this->canonicalResultFingerprint;
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            self::string($data, 'provider_identifier'), self::string($data, 'capability'),
            DevelopmentExecutionOutcome::from(self::string($data, 'outcome')),
            self::objects($data['stage_results'] ?? null, DevelopmentStageResult::fromArray(...)), self::strings($data['implementation_plan'] ?? null),
            self::objects($data['changed_files'] ?? null, DevelopmentChangedFile::fromArray(...)), self::string($data, 'diff_summary'),
            self::objects($data['validation_results'] ?? null, DevelopmentValidationResult::fromArray(...)),
            DevelopmentRepositoryArtifact::fromArray(self::object($data['synthetic_branch_result'] ?? null)),
            self::artifact($data['synthetic_commit_result'] ?? null), self::artifact($data['synthetic_push_result'] ?? null),
            self::artifact($data['synthetic_pull_request_result'] ?? null), self::string($data, 'target_branch'),
            self::strings($data['assumptions'] ?? null), self::float($data, 'confidence'), self::strings($data['risks'] ?? null),
            self::strings($data['evidence_gaps'] ?? null),
            DevelopmentSimulationClassification::from(self::string($data, 'simulation_classification')),
            DevelopmentVerificationClassification::from(self::string($data, 'verification_classification')),
            DevelopmentFailureClassification::from(self::string($data, 'retry_classification')),
            self::string($data, 'recommended_next_action'), self::string($data, 'canonical_result_fingerprint'),
            self::integer($data, 'schema_version'),
        );
    }

    private static function artifact(mixed $value): ?DevelopmentRepositoryArtifact
    {
        return $value === null ? null : DevelopmentRepositoryArtifact::fromArray(self::object($value));
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException('Development result object field is malformed.');
        }

        return $value;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || array_any($value, static fn (mixed $item): bool => ! is_string($item))) {
            throw new \InvalidArgumentException('Development result list field is malformed.');
        }

        return $value;
    }

    /** @return list<mixed> */
    private static function objects(mixed $value, callable $factory): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new \InvalidArgumentException('Development result object list is malformed.');
        }

        return array_map(static fn (mixed $item): mixed => $factory(self::object($item)), $value);
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        if (! array_key_exists($key, $data) || ! is_string($data[$key])) {
            throw new \InvalidArgumentException("Development result {$key} field is malformed.");
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function integer(array $data, string $key): int
    {
        if (! array_key_exists($key, $data) || ! is_int($data[$key])) {
            throw new \InvalidArgumentException("Development result {$key} field is malformed.");
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function float(array $data, string $key): float
    {
        if (! array_key_exists($key, $data) || (! is_float($data[$key]) && ! is_int($data[$key]))) {
            throw new \InvalidArgumentException("Development result {$key} field is malformed.");
        }

        return (float) $data[$key];
    }
}
