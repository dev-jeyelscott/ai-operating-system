<?php

declare(strict_types=1);

use App\Models\OfficeProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('prepares an idempotent office browser fixture', function (): void {
    $this
        ->artisan('app:e2e:prepare-office', [
            '--sequence' => 42,
            '--json' => true,
        ])
        ->assertSuccessful();

    $this
        ->artisan('app:e2e:prepare-office', [
            '--sequence' => 43,
            '--json' => true,
        ])
        ->assertSuccessful();

    expect(OfficeProjection::query()->count())->toBe(1);

    $projection = OfficeProjection::query()->firstOrFail();

    expect($projection->last_event_sequence)->toBe(43)
        ->and(
            data_get(
                $projection->state,
                'agents.0.officeState',
            ),
        )->toBe('validating');
});
