<?php

declare(strict_types=1);

use App\Domain\Documents\DocumentStatus;
use App\Domain\Projects\ProjectStatus;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DeterministicDemoSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

it('seeds the four required realistic demonstration projects', function (): void {
    $this->seed(DeterministicDemoSeeder::class);

    $organization = Organization::query()
        ->where('slug', 'aios-demonstration')
        ->firstOrFail();

    $projects = Project::query()
        ->forOrganization($organization->id)
        ->orderBy('slug')
        ->get();

    expect(User::query()->where('email', 'demo-owner@example.test')->exists())
        ->toBeTrue()
        ->and($projects)->toHaveCount(4)
        ->and($projects->pluck('slug')->all())->toBe([
            'demo-conflicting-documents',
            'demo-happy-path',
            'demo-high-risk-merge',
            'demo-notion-transient-failure',
        ])
        ->and($projects->firstWhere('slug', 'demo-happy-path')?->status)
        ->toBe(ProjectStatus::ReadyForPlanning)
        ->and($projects->firstWhere('slug', 'demo-notion-transient-failure')?->status)
        ->toBe(ProjectStatus::Blocked)
        ->and($projects->firstWhere('slug', 'demo-high-risk-merge')?->status)
        ->toBe(ProjectStatus::Active);
});

it('seeds approved analyzed documents with persistent simulation labels', function (): void {
    $this->seed(DeterministicDemoSeeder::class);

    $versions = DocumentVersion::query()
        ->with('document.project')
        ->orderBy('id')
        ->get();

    expect($versions)->toHaveCount(11);

    foreach ($versions as $version) {
        expect($version->status)->toBe(DocumentStatus::Approved)
            ->and($version->analysis_flags)->toContain('simulation_only')
            ->and($version->analysis_flags)->toContain('actual_state_unverified')
            ->and(Storage::disk('local')->exists($version->storage_path))
            ->toBeTrue()
            ->and(hash(
                'sha256',
                Storage::disk('local')->get($version->storage_path),
            ))->toBe($version->checksum_sha256);
    }
});

it('includes explicit conflict, failure, and high-risk document evidence', function (): void {
    $this->seed(DeterministicDemoSeeder::class);

    $conflict = DocumentVersion::query()
        ->whereHas('document', fn ($query) => $query
            ->where('title', 'Conflicting Architecture'))
        ->firstOrFail();
    $notionFailure = DocumentVersion::query()
        ->whereHas('document', fn ($query) => $query
            ->where('title', 'Notion Recovery Runbook'))
        ->firstOrFail();
    $highRisk = DocumentVersion::query()
        ->whereHas('document', fn ($query) => $query
            ->where('title', 'Database Migration Plan'))
        ->firstOrFail();

    expect($conflict->analysis_conflicts)->not->toBeEmpty()
        ->and($conflict->analysis_flags)->toContain('human_decision_required')
        ->and($notionFailure->analysis_flags)->toContain('notion_transient_failure')
        ->and($notionFailure->analysis_flags)->toContain('retry_required')
        ->and($highRisk->analysis_gaps)->not->toBeEmpty()
        ->and($highRisk->analysis_flags)->toContain('high_risk');
});

it('is idempotent when the same demo seeder is executed twice', function (): void {
    $this->seed(DeterministicDemoSeeder::class);
    $this->seed(DeterministicDemoSeeder::class);

    expect(User::query()->where('email', 'demo-owner@example.test')->count())
        ->toBe(1)
        ->and(Organization::query()->where('slug', 'aios-demonstration')->count())
        ->toBe(1)
        ->and(Project::query()->whereIn('slug', [
            'demo-happy-path',
            'demo-conflicting-documents',
            'demo-notion-transient-failure',
            'demo-high-risk-merge',
        ])->count())->toBe(4)
        ->and(DocumentVersion::query()->count())->toBe(11);
});
