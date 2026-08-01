<?php

declare(strict_types=1);

use App\Application\Development\DevelopmentArtifactRecorder;
use App\Models\Evidence;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/** @return array{project:Project,execution:Execution,attempt:ExecutionAttempt} */
function aios099RecorderFixture(): array
{
    $project = Project::factory()->create();
    $execution = Execution::factory()->for($project)->create([
        'capability' => 'development.simulation',
    ]);
    $attempt = ExecutionAttempt::factory()
        ->for($execution)
        ->completed()
        ->create([
            'simulation_mode' => 'simulated',
            'simulation_seed' => '99',
        ]);

    return compact('project', 'execution', 'attempt');
}

test(
    'DevelopmentArtifactRecorder sequential replay creates one artifact and evidence row',
    function (): void {
        $fixture = aios099RecorderFixture();
        $recorder = app(DevelopmentArtifactRecorder::class);
        $arguments = [
            $fixture['execution'],
            $fixture['attempt'],
            'synthetic_branch',
            'feature/aios-099-artifacts',
            'simulation://projects/1/executions/example/branches/feature/aios-099-artifacts',
            ['target' => 'develop'],
            ['Synthetic branch generated.'],
        ];

        $first = $recorder->record(...$arguments);
        $second = $recorder->record(...$arguments);

        expect($second->id)->toBe($first->id)
            ->and($first->idempotency_key)->not->toBeNull()
            ->and($first->content_fingerprint_sha256)
            ->toMatch('/\A[a-f0-9]{64}\z/');

        $this->assertDatabaseCount('artifacts', 1);
        $this->assertDatabaseCount('evidence', 1);
    },
);

test(
    'DevelopmentArtifactRecorder rejects payload drift for the same ownership key',
    function (): void {
        $fixture = aios099RecorderFixture();
        $recorder = app(DevelopmentArtifactRecorder::class);

        $recorder->record(
            $fixture['execution'],
            $fixture['attempt'],
            'synthetic_branch',
            'feature/aios-099',
            'simulation://branch/one',
            [],
            ['One.'],
        );

        expect(fn () => $recorder->record(
            $fixture['execution'],
            $fixture['attempt'],
            'synthetic_branch',
            'feature/aios-099-drift',
            'simulation://branch/two',
            [],
            ['Two.'],
        ))->toThrow(
            LogicException::class,
            'Artifact idempotency key conflicts with different content.',
        );

        $this->assertDatabaseCount('artifacts', 1);
        $this->assertDatabaseCount('evidence', 1);
    },
);

test(
    'attempt failures are attempt scoped while final artifacts are execution scoped',
    function (): void {
        $fixture = aios099RecorderFixture();

        $secondAttempt = ExecutionAttempt::factory()
            ->for($fixture['execution'])
            ->failed()
            ->create([
                'attempt_number' => 2,
                'simulation_mode' => 'simulated',
                'simulation_seed' => '99',
            ]);

        $recorder = app(DevelopmentArtifactRecorder::class);

        $first = $recorder->record(
            $fixture['execution'],
            $fixture['attempt'],
            'validation_failure',
            'Failed validation',
            'simulation://failures/one',
            [],
            ['Failed.'],
        );

        $second = $recorder->record(
            $fixture['execution'],
            $secondAttempt,
            'validation_failure',
            'Failed validation',
            'simulation://failures/two',
            [],
            ['Failed again.'],
        );

        expect($second->id)->not->toBe($first->id);

        $this->assertDatabaseCount('artifacts', 2);
        $this->assertDatabaseCount('evidence', 2);
    },
);

test(
    'a new logical execution owns a distinct artifact row',
    function (): void {
        $fixture = aios099RecorderFixture();

        $otherExecution = Execution::factory()
            ->for($fixture['project'])
            ->create([
                'capability' => 'development.simulation',
            ]);

        $otherAttempt = ExecutionAttempt::factory()
            ->for($otherExecution)
            ->completed()
            ->create([
                'simulation_mode' => 'simulated',
                'simulation_seed' => '99',
            ]);

        $recorder = app(DevelopmentArtifactRecorder::class);

        $first = $recorder->record(
            $fixture['execution'],
            $fixture['attempt'],
            'synthetic_branch',
            'feature/same',
            'simulation://first/branch',
            [],
            ['Branch.'],
        );

        $second = $recorder->record(
            $otherExecution,
            $otherAttempt,
            'synthetic_branch',
            'feature/same',
            'simulation://second/branch',
            [],
            ['Branch.'],
        );

        expect($second->id)->not->toBe($first->id);

        $this->assertDatabaseCount('artifacts', 2);
    },
);

test(
    'artifact and evidence roll back together',
    function (): void {
        $fixture = aios099RecorderFixture();

        try {
            DB::transaction(function () use ($fixture): void {
                app(DevelopmentArtifactRecorder::class)->record(
                    $fixture['execution'],
                    $fixture['attempt'],
                    'synthetic_commit',
                    str_repeat('a', 40),
                    'simulation://commit/rollback',
                    [],
                    ['Commit.'],
                );

                throw new RuntimeException('roll back');
            });
        } catch (RuntimeException) {
            // Expected test-controlled rollback.
        }

        $this->assertDatabaseCount('artifacts', 0);
        $this->assertDatabaseCount('evidence', 0);
    },
);

test(
    'DevelopmentArtifactRecorder redacts sensitive content before persistence',
    function (): void {
        $fixture = aios099RecorderFixture();
        $token = 'ntn_'.str_repeat('C', 32);

        $artifact = app(
            DevelopmentArtifactRecorder::class,
        )->record(
            execution: $fixture['execution'],
            attempt: $fixture['attempt'],
            type: 'synthetic_branch',
            name: "feature/sensitive-{$token}",
            reference: "simulation://branch?token={$token}",
            metadata: [
                'provider_token' => $token,
                'summary' => "Generated using {$token}",
            ],
            claims: [
                "Synthetic branch used {$token}.",
            ],
        );

        $artifact->load('evidence');

        $serialized = json_encode([
            'name' => $artifact->name,
            'reference' => $artifact->external_reference,
            'metadata' => $artifact->metadata,
            'evidence' => $artifact->evidence
                ->map(fn (Evidence $evidence): array => [
                    'source_reference' => $evidence
                        ->source_reference,
                    'claims' => $evidence->claims,
                    'metadata' => $evidence->metadata,
                ])
                ->all(),
        ], JSON_THROW_ON_ERROR);

        expect($serialized)
            ->not->toContain($token)
            ->toContain('[REDACTED]');
    },
);
