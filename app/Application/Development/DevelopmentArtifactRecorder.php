<?php

declare(strict_types=1);

namespace App\Application\Development;

use App\Domain\Evidence\EvidenceClassification;
use App\Models\Artifact;
use App\Models\Evidence;
use App\Models\Execution;
use App\Models\ExecutionAttempt;

class DevelopmentArtifactRecorder
{
    /**
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
        $artifact = Artifact::query()->create([
            'project_id' => $execution->project_id, 'execution_id' => $execution->id,
            'execution_attempt_id' => $attempt->id, 'artifact_type' => $type, 'name' => $name,
            'execution_provider' => 'simulation', 'external_reference' => $reference,
            'simulation_mode' => 'simulated', 'simulation_seed' => $attempt->simulation_seed,
            'assumptions' => ['Synthetic output only.'], 'confidence' => '0.7500',
            'evidence_still_required' => true, 'actual_state' => 'unverified',
            'metadata' => ['synthetic' => true, ...$metadata],
        ]);
        Evidence::query()->create([
            'artifact_id' => $artifact->id, 'classification' => EvidenceClassification::SimulatedOutput,
            'evidence_type' => $type, 'provider' => 'simulation', 'source_reference' => $reference,
            'commit_sha' => $type === 'synthetic_commit' ? $name : null, 'claims' => $claims,
            'confidence' => '0.7500', 'metadata' => ['synthetic' => true, 'actual_state' => 'unverified'],
        ]);

        return $artifact;
    }
}
