<?php

declare(strict_types=1);

use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

it('prepares the complete deterministic local demo', function (): void {
    $this->artisan('app:demo:prepare')
        ->expectsOutputToContain(
            'Deterministic demo environment prepared successfully.',
        )
        ->expectsOutputToContain('demo-owner@example.test')
        ->expectsOutputToContain('Simulated and unverified')
        ->assertSuccessful();

    expect(
        User::query()
            ->where('email', 'demo-owner@example.test')
            ->count(),
    )->toBe(1)
        ->and(
            Organization::query()
                ->where('slug', 'aios-demonstration')
                ->count(),
        )->toBe(1)
        ->and(
            Project::query()
                ->whereIn('slug', [
                    'demo-happy-path',
                    'demo-conflicting-documents',
                    'demo-notion-transient-failure',
                    'demo-high-risk-merge',
                ])
                ->count(),
        )->toBe(4)
        ->and(DocumentVersion::query()->count())
        ->toBe(11);
});

it('prints a machine-readable deterministic manifest', function (): void {
    $exitCode = Artisan::call('app:demo:prepare', [
        '--json' => true,
    ]);

    $manifest = json_decode(
        trim(Artisan::output()),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($exitCode)->toBe(0)
        ->and($manifest['schemaVersion'])->toBe(1)
        ->and($manifest['environment'])->toBe('testing')
        ->and($manifest['credentials'])->toBe([
            'email' => 'demo-owner@example.test',
            'password' => 'Demo-Password-2026',
        ])
        ->and($manifest['organization']['slug'])
        ->toBe('aios-demonstration')
        ->and($manifest['simulation'])->toBe([
            'provider' => 'simulation',
            'actualState' => 'unverified',
            'evidenceStillRequired' => true,
        ])
        ->and($manifest['projects'])->toHaveCount(4)
        ->and(
            collect($manifest['projects'])
                ->pluck('slug')
                ->sort()
                ->values()
                ->all(),
        )->toBe([
            'demo-conflicting-documents',
            'demo-happy-path',
            'demo-high-risk-merge',
            'demo-notion-transient-failure',
        ])
        ->and($manifest['nextCommand'])->toBe('./bin/dev');
});

it('remains idempotent when executed repeatedly', function (): void {
    Artisan::call('app:demo:prepare', ['--json' => true]);
    Artisan::call('app:demo:prepare', ['--json' => true]);

    expect(
        User::query()
            ->where('email', 'demo-owner@example.test')
            ->count(),
    )->toBe(1)
        ->and(
            Organization::query()
                ->where('slug', 'aios-demonstration')
                ->count(),
        )->toBe(1)
        ->and(
            Project::query()
                ->whereIn('slug', [
                    'demo-happy-path',
                    'demo-conflicting-documents',
                    'demo-notion-transient-failure',
                    'demo-high-risk-merge',
                ])
                ->count(),
        )->toBe(4)
        ->and(DocumentVersion::query()->count())
        ->toBe(11);
});

it('refuses to prepare demo data in production', function (): void {
    $originalEnvironment = app()->environment();

    app()->instance('env', 'production');

    try {
        $this->artisan('app:demo:prepare')
            ->expectsOutputToContain(
                'The deterministic demo may only be prepared in local or testing environments.',
            )
            ->assertFailed();

        expect(
            User::query()
                ->where('email', 'demo-owner@example.test')
                ->exists(),
        )->toBeFalse();
    } finally {
        app()->instance('env', $originalEnvironment);
    }
});
