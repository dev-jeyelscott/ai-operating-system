<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TicketTestFixture;

test('ticket state migration rolls back and upgrades legacy rows with constrained defaults', function (): void {
    $fixture = TicketTestFixture::create();
    $migration = require database_path('migrations/2026_07_28_093811_add_ticket_state_fields_to_roadmap_tasks_table.php');

    $migration->down();

    expect(Schema::hasColumn('roadmap_tasks', 'status'))->toBeFalse()
        ->and(Schema::hasColumn('roadmap_tasks', 'actual_state'))->toBeFalse();

    $migration->up();

    $legacyRow = DB::table('roadmap_tasks')->where('id', $fixture['ticket']->id)->first();
    expect(Schema::hasColumns('roadmap_tasks', [
        'status', 'desired_state', 'reported_state', 'observed_state',
        'actual_state', 'status_changed_at', 'ready_at',
    ]))->toBeTrue()
        ->and($legacyRow->status)->toBe('backlog')
        ->and($legacyRow->desired_state)->toBe('backlog')
        ->and($legacyRow->actual_state)->toBe('unverified')
        ->and($legacyRow->status_changed_at)->not->toBeNull();

    $constraintNames = DB::table('pg_constraint')
        ->whereIn('conname', [
            'roadmap_tasks_status_check',
            'roadmap_tasks_desired_state_check',
            'roadmap_tasks_actual_state_check',
        ])
        ->pluck('conname')
        ->sort()
        ->values()
        ->all();
    expect($constraintNames)->toBe([
        'roadmap_tasks_actual_state_check',
        'roadmap_tasks_desired_state_check',
        'roadmap_tasks_status_check',
    ]);

    $rejected = false;
    try {
        DB::transaction(static function () use ($fixture): void {
            DB::table('roadmap_tasks')->where('id', $fixture['ticket']->id)->update(['status' => 'invented']);
        });
    } catch (QueryException) {
        $rejected = true;
    }

    expect($rejected)->toBeTrue();
});
