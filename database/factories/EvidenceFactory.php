<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Evidence\EvidenceClassification;
use App\Models\Artifact;
use App\Models\Evidence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Evidence>
 */
final class EvidenceFactory extends Factory
{
    /**
     * Define one immutable simulated-output evidence record.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'artifact_id' => Artifact::factory(),
            'classification' => EvidenceClassification::SimulatedOutput,
            'evidence_type' => 'validation_result',
            'provider' => 'simulation',
            'source_reference' => 'simulation://evidence/default',
            'commit_sha' => null,
            'claims' => [
                'The simulated validation scenario completed successfully.',
            ],
            'verification_method' => null,
            'observed_at' => null,
            'verified_at' => null,
            'expires_at' => null,
            'rejection_reason' => null,
            'confidence' => '0.9000',
            'metadata' => [
                'actual_state' => 'unverified',
            ],
        ];
    }

    /**
     * Derive provider and source provenance from the selected artifact.
     */
    public function configure(): static
    {
        return $this->afterMaking(
            function (Evidence $evidence): void {
                /** @var Artifact $artifact */
                $artifact = Artifact::query()
                    ->findOrFail($evidence->artifact_id);

                $evidence->forceFill([
                    'provider' => $artifact->execution_provider,
                    'source_reference' => $artifact->external_reference
                        ?? sprintf('artifact://%s', $artifact->id),
                ]);
            },
        );
    }

    /**
     * Bind this evidence record to one explicit artifact.
     */
    public function forArtifact(Artifact $artifact): static
    {
        return $this->state(fn (): array => [
            'artifact_id' => $artifact->id,
            'provider' => $artifact->execution_provider,
            'source_reference' => $artifact->external_reference
                ?? sprintf('artifact://%s', $artifact->id),
        ]);
    }

    /**
     * Classify the record as an assumption.
     */
    public function assumption(): static
    {
        return $this->state(fn (): array => [
            'classification' => EvidenceClassification::Assumption,
            'claims' => ['The implementation assumes PostgreSQL is available.'],
            'confidence' => null,
        ]);
    }

    /**
     * Classify the record as a proposal.
     */
    public function proposal(): static
    {
        return $this->state(fn (): array => [
            'classification' => EvidenceClassification::Proposal,
            'claims' => ['The provider proposes adding one validation command.'],
            'confidence' => '0.7000',
        ]);
    }

    /**
     * Classify the record as provider-reported evidence.
     */
    public function reported(): static
    {
        return $this->state(fn (): array => [
            'classification' => EvidenceClassification::ReportedEvidence,
            'claims' => ['The provider reported that validation passed.'],
            'confidence' => '0.8000',
        ]);
    }

    /**
     * Classify the record as directly observed evidence.
     */
    public function observed(): static
    {
        return $this->state(fn (): array => [
            'classification' => EvidenceClassification::ObservedEvidence,
            'claims' => ['A validation result was observed at the source.'],
            'verification_method' => null,
            'observed_at' => now(),
            'verified_at' => null,
            'rejection_reason' => null,
            'confidence' => '0.9000',
        ]);
    }

    /**
     * Classify the record as verified evidence from a non-simulation artifact.
     */
    public function verified(): static
    {
        $observedAt = now()->subSecond();

        return $this->state(fn (): array => [
            'artifact_id' => Artifact::factory()->fromRealProvider(),
            'classification' => EvidenceClassification::VerifiedEvidence,
            'claims' => ['The validation source and immutable reference were verified.'],
            'verification_method' => 'Matched the provider source and immutable checksum.',
            'observed_at' => $observedAt,
            'verified_at' => now(),
            'rejection_reason' => null,
            'confidence' => '1.0000',
        ]);
    }

    /**
     * Classify the record as rejected evidence.
     */
    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'classification' => EvidenceClassification::RejectedEvidence,
            'claims' => ['The reported result could not be matched to its source.'],
            'verification_method' => 'Compared the report with the external source reference.',
            'observed_at' => now(),
            'verified_at' => null,
            'rejection_reason' => 'The immutable source reference did not exist.',
            'confidence' => '1.0000',
        ]);
    }
}
