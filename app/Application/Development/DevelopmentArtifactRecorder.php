<?php

declare(strict_types=1);

namespace App\Application\Development;

use App\Application\Security\RedactSensitiveData;
use App\Domain\Evidence\EvidenceClassification;
use App\Models\Artifact;
use App\Models\Evidence;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

class DevelopmentArtifactRecorder
{
    /**
     * Inject canonical serialization and security redaction.
     */
    public function __construct(
        private readonly DevelopmentResultValidator $canonical,
        private readonly RedactSensitiveData $redactor,
    ) {}

    /**
     * Persist one sanitized simulated artifact and its evidence.
     *
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $claims
     */
    public function record(
        Execution $execution,
        ExecutionAttempt $attempt,
        string $type,
        string $name,
        string $reference,
        array $metadata,
        array $claims,
    ): Artifact {
        /*
         * Redaction must occur before fingerprint generation. This guarantees
         * that the stored fingerprint describes the persisted safe content.
         */
        $simulated = $attempt->simulation_mode !== null;

        $classification = $simulated
            ? EvidenceClassification::SimulatedOutput
            : EvidenceClassification::ReportedEvidence;

        $actualState = 'unverified';

        $safeName = $this->redactor->message($name);
        $safeReference = $this->redactor->message(
            $reference,
        );
        $safeMetadata = $this->redactor->values(
            $metadata,
        );
        $safeClaims = $this->redactor->strings(
            $claims,
        );

        $idempotencyKey = $this->artifactKey(
            $execution,
            $attempt,
            $type,
        );

        $artifactMetadata = [
            'synthetic' => $simulated,
            ...$safeMetadata,
        ];

        $artifactFingerprint = $this->fingerprint([
            'type' => $type,
            'name' => $safeName,
            'reference' => $safeReference,
            'metadata' => $artifactMetadata,
        ]);

        $provenanceKey = sprintf(
            'development:%s:%s_output',
            $type,
            $simulated ? 'simulated' : 'provider',
        );

        $evidenceMetadata = [
            'synthetic' => $simulated,
            'actual_state' => $actualState,
        ];

        $evidenceFingerprint = $this->fingerprint([
            'classification' => EvidenceClassification::SimulatedOutput
                ->value,
            'type' => $type,
            'provider' => 'simulation',
            'reference' => $safeReference,
            'claims' => $safeClaims,
            'metadata' => $evidenceMetadata,
        ]);

        try {
            return DB::transaction(function () use (
                $execution,
                $attempt,
                $type,
                $safeName,
                $safeReference,
                $safeClaims,
                $idempotencyKey,
                $artifactMetadata,
                $artifactFingerprint,
                $provenanceKey,
                $evidenceMetadata,
                $evidenceFingerprint,
                $simulated,
                $actualState,
                $classification
            ): Artifact {
                $artifact = Artifact::query()->create([
                    'project_id' => $execution->project_id,
                    'execution_id' => $execution->id,
                    'execution_attempt_id' => $attempt->id,
                    'artifact_type' => $type,
                    'name' => $safeName,
                    'execution_provider' => $attempt->execution_provider,
                    'external_reference' => $safeReference,
                    'simulation_mode' => $attempt->simulation_mode,
                    'simulation_seed' => $attempt->simulation_seed,
                    'assumptions' => $simulated
                        ? ['Synthetic output only.']
                        : [],
                    'confidence' => $simulated
                        ? '0.7500'
                        : null,
                    'evidence_still_required' => true,
                    'actual_state' => $actualState,
                    'metadata' => $artifactMetadata,
                    'idempotency_key' => $idempotencyKey,
                    'content_fingerprint_sha256' => $artifactFingerprint,
                ]);

                Evidence::query()->create([
                    'artifact_id' => $artifact->id,
                    'classification' => $classification,
                    'evidence_type' => $type,
                    'provider' => $attempt->execution_provider,
                    'source_reference' => $safeReference,
                    'commit_sha' => $type === 'synthetic_commit'
                        ? $safeName
                        : null,
                    'claims' => $safeClaims,
                    'confidence' => $simulated
                        ? '0.7500'
                        : null,
                    'metadata' => $evidenceMetadata,
                    'provenance_key' => $provenanceKey,
                    'content_fingerprint_sha256' => $evidenceFingerprint,
                ]);

                return $artifact;
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23505') {
                throw $exception;
            }

            $artifact = Artifact::query()
                ->forProject($execution->project_id)
                ->where(
                    'idempotency_key',
                    $idempotencyKey,
                )
                ->first();

            if ($artifact === null) {
                throw $exception;
            }

            if (! hash_equals(
                (string) $artifact
                    ->content_fingerprint_sha256,
                $artifactFingerprint,
            )) {
                throw new LogicException(
                    'Artifact idempotency key conflicts with different content.',
                );
            }

            $evidence = $artifact
                ->evidence()
                ->where(
                    'provenance_key',
                    $provenanceKey,
                )
                ->first();

            if (
                $evidence === null
                || ! hash_equals(
                    (string) $evidence
                        ->content_fingerprint_sha256,
                    $evidenceFingerprint,
                )
            ) {
                throw new LogicException(
                    'Evidence provenance key conflicts with different content.',
                );
            }

            return $artifact;
        }
    }

    /**
     * Build one stable artifact idempotency key.
     */
    private function artifactKey(
        Execution $execution,
        ExecutionAttempt $attempt,
        string $type,
    ): string {
        $scope = in_array(
            $type,
            [
                'validation_failure',
                'provider_failure',
                'timeout_report',
            ],
            true,
        )
            ? sprintf('attempt:%d', $attempt->id)
            : 'execution';

        return sprintf(
            'development:%s:%s:%s',
            $execution->id,
            $scope,
            $type,
        );
    }

    /**
     * Produce a deterministic SHA-256 fingerprint.
     *
     * @param  array<string, mixed>  $content
     */
    private function fingerprint(array $content): string
    {
        return hash(
            'sha256',
            $this->canonical->canonicalJson($content),
        );
    }
}
