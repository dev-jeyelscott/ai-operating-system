<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('creates valid and ready AIOS-144 PostgreSQL indexes', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped(
            'AIOS-144 index validation requires PostgreSQL.',
        );
    }

    $expectedIndexNames = [
        'roadmaps_approved_project_revision_idx',
        'roadmap_tasks_workable_candidates_idx',
        'roadmap_tasks_projection_order_idx',
        'ticket_execution_leases_project_active_idx',
        'audit_events_organization_sequence_idx',
        'audit_events_project_sequence_idx',
        'notification_recipients_inbox_idx',
        'notification_recipients_unread_idx',
        'outbox_messages_project_sequence_idx',
        'executions_project_created_idx',
        'execution_attempts_latest_idx',
        'approvals_project_pending_idx',
    ];

    $indexes = DB::table('pg_class as index_relation')
        ->join(
            'pg_index as index_definition',
            'index_definition.indexrelid',
            '=',
            'index_relation.oid',
        )
        ->join(
            'pg_namespace as namespace',
            'namespace.oid',
            '=',
            'index_relation.relnamespace',
        )
        ->where('namespace.nspname', 'public')
        ->whereIn(
            'index_relation.relname',
            $expectedIndexNames,
        )
        ->selectRaw(
            <<<'SQL'
                index_relation.relname AS index_name,
                index_definition.indisvalid AS is_valid,
                index_definition.indisready AS is_ready
                SQL,
        )
        ->get()
        ->keyBy('index_name');

    expect($indexes)->toHaveCount(
        count($expectedIndexNames),
    );

    $postgresBoolean = static fn (mixed $value): bool => in_array(
        $value,
        [true, 1, '1', 't', 'true'],
        true,
    );

    foreach ($expectedIndexNames as $indexName) {
        $index = $indexes->get($indexName);

        expect($index)
            ->not->toBeNull()
            ->and($postgresBoolean($index->is_valid))
            ->toBeTrue()
            ->and($postgresBoolean($index->is_ready))
            ->toBeTrue();
    }
});
