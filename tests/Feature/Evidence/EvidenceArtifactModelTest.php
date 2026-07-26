<?php

declare(strict_types=1);

use App\Domain\Evidence\EvidenceClassification;
use App\Models\Artifact;
use App\Models\Evidence;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('stores artifact provenance and classified evidence separately', function (): void {
    $artifact = Artifact::factory()->create();
    $evidence = Evidence::factory()
        ->forArtifact($artifact)
        ->create();

    $artifact->refresh();
    $evidence->refresh();

    expect($artifact->isSimulated())
        ->toBeTrue()
        ->and($artifact->actual_state)
        ->toBe('unverified')
        ->and($artifact->evidence_still_required)
        ->toBeTrue()
        ->and($evidence->classification)
        ->toBe(EvidenceClassification::SimulatedOutput)
        ->and($evidence->isVerified())
        ->toBeFalse()
        ->and($evidence->artifact->is($artifact))
        ->toBeTrue();
});

it('persists every approved evidence classification distinctly', function (): void {
    $simulatedArtifact = Artifact::factory()->create();
    $realArtifact = Artifact::factory()
        ->fromRealProvider()
        ->create();

    Evidence::factory()
        ->forArtifact($simulatedArtifact)
        ->assumption()
        ->create();

    Evidence::factory()
        ->forArtifact($simulatedArtifact)
        ->proposal()
        ->create();

    Evidence::factory()
        ->forArtifact($simulatedArtifact)
        ->create();

    Evidence::factory()
        ->forArtifact($simulatedArtifact)
        ->reported()
        ->create();

    Evidence::factory()
        ->forArtifact($simulatedArtifact)
        ->observed()
        ->create();

    Evidence::factory()
        ->verified()
        ->forArtifact($realArtifact)
        ->create();

    Evidence::factory()
        ->forArtifact($simulatedArtifact)
        ->rejected()
        ->create();

    $classifications = Evidence::query()
        ->orderBy('classification')
        ->get()
        ->map(
            static fn (Evidence $evidence): string => $evidence->classification->value,
        )
        ->all();

    expect($classifications)->toBe([
        'assumption',
        'observed_evidence',
        'proposal',
        'rejected_evidence',
        'reported_evidence',
        'simulated_output',
        'verified_evidence',
    ]);
});

it('scopes artifacts and evidence through explicit project ownership', function (): void {
    $firstArtifact = Artifact::factory()->create();
    $secondArtifact = Artifact::factory()->create();

    $firstEvidence = Evidence::factory()
        ->forArtifact($firstArtifact)
        ->create();

    Evidence::factory()
        ->forArtifact($secondArtifact)
        ->create();

    expect(
        Artifact::query()
            ->forProject($firstArtifact->project_id)
            ->pluck('id')
            ->all(),
    )->toBe([$firstArtifact->id])
        ->and(
            Evidence::query()
                ->forProject($firstArtifact->project_id)
                ->pluck('id')
                ->all(),
        )
        ->toBe([$firstEvidence->id]);
});

it('rejects cross-execution attempt lineage', function (): void {
    $attempt = ExecutionAttempt::factory()->create();
    $otherExecution = Execution::factory()->create();

    $artifact = Artifact::factory()
        ->forAttempt($attempt)
        ->make();

    $artifact->forceFill([
        'project_id' => $otherExecution->project_id,
        'execution_id' => $otherExecution->id,
    ]);

    expect(fn () => $artifact->save())
        ->toThrow(QueryException::class);
});

it('rejects artifact provenance that differs from its attempt', function (): void {
    $attempt = ExecutionAttempt::factory()->create();

    $artifact = Artifact::factory()
        ->forAttempt($attempt)
        ->make();

    $artifact->forceFill([
        'execution_provider' => 'github',
        'simulation_mode' => null,
        'simulation_seed' => null,
        'actual_state' => 'observed',
    ]);

    expect(fn () => $artifact->save())
        ->toThrow(QueryException::class);
});

it('prevents simulated artifacts from receiving verified evidence', function (): void {
    $artifact = Artifact::factory()->create();

    expect(
        fn () => Evidence::factory()
            ->verified()
            ->forArtifact($artifact)
            ->create(),
    )->toThrow(QueryException::class);
});

it('allows verified evidence for a non-simulation artifact', function (): void {
    $artifact = Artifact::factory()
        ->fromRealProvider()
        ->create();

    $evidence = Evidence::factory()
        ->verified()
        ->forArtifact($artifact)
        ->create();

    expect($evidence->fresh()?->isVerified())
        ->toBeTrue()
        ->and($evidence->verified_at)
        ->not->toBeNull();
});

it('rejects lifecycle fields that contradict the classification', function (): void {
    $artifact = Artifact::factory()->create();

    $evidence = Evidence::factory()
        ->forArtifact($artifact)
        ->reported()
        ->make([
            'observed_at' => now(),
            'verified_at' => now(),
            'verification_method' => 'Invalid in-place upgrade.',
        ]);

    expect(fn () => $evidence->save())
        ->toThrow(QueryException::class);
});

it('rejects model-level artifact and evidence mutation', function (): void {
    $artifact = Artifact::factory()->create();
    $evidence = Evidence::factory()
        ->forArtifact($artifact)
        ->create();

    $artifact->forceFill(['name' => 'Mutated artifact']);

    expect(fn () => $artifact->save())
        ->toThrow(LogicException::class, 'Artifacts are immutable.');

    $evidence->forceFill([
        'classification' => EvidenceClassification::ReportedEvidence,
    ]);

    expect(fn () => $evidence->save())
        ->toThrow(LogicException::class, 'Evidence records are immutable.');
});

it('rejects raw database updates to append-only records', function (): void {
    $artifact = Artifact::factory()->create();

    expect(
        fn () => DB::table('artifacts')
            ->where('id', $artifact->id)
            ->update(['name' => 'Raw mutation']),
    )->toThrow(QueryException::class);
});
