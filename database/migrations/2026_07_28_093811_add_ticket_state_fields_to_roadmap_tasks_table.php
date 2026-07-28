<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the authoritative ticket status and evidence-backed state fields.
     */
    public function up(): void
    {
        Schema::table('roadmap_tasks', function (Blueprint $table): void {
            /*
             * Status is authoritative internal workflow truth.
             * Existing roadmap tasks safely begin in backlog.
             */
            $table->string('status', 40)
                ->default('backlog');

            /*
             * Desired state records intent without claiming that the transition
             * has already happened.
             */
            $table->string('desired_state', 40)
                ->default('backlog');

            /*
             * Reported state contains a normalized claim received from Notion,
             * an execution provider, or another external integration.
             */
            $table->string('reported_state', 120)
                ->nullable();

            /*
             * Observed state contains a normalized condition directly observed
             * by deterministic application infrastructure.
             */
            $table->string('observed_state', 120)
                ->nullable();

            /*
             * Actual state reflects evidence strength. New and simulated tickets
             * must remain unverified until deterministic verification occurs.
             */
            $table->string('actual_state', 40)
                ->default('unverified');

            /*
             * Keep authoritative status timing separate from external reports.
             */
            $table->timestampTz(
                'status_changed_at',
                precision: 6,
            )->useCurrent();

            /*
             * AIOS-091 will use this value for the oldest-ready tie breaker.
             */
            $table->timestampTz(
                'ready_at',
                precision: 6,
            )->nullable();

            /*
             * Future selectors first scope to an approved roadmap, then filter
             * by status and ready time.
             */
            $table->index(
                [
                    'roadmap_id',
                    'status',
                    'ready_at',
                    'id',
                ],
                'roadmap_tasks_roadmap_status_ready_index',
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE roadmap_tasks
                ADD CONSTRAINT roadmap_tasks_status_check
                    CHECK (
                        status IN (
                            'backlog',
                            'ready',
                            'in_progress',
                            'blocked',
                            'for_qa',
                            'changes_requested',
                            'approved_for_merge',
                            'done',
                            'cancelled'
                        )
                    ),
                ADD CONSTRAINT roadmap_tasks_desired_state_check
                    CHECK (
                        desired_state IN (
                            'backlog',
                            'ready',
                            'in_progress',
                            'blocked',
                            'for_qa',
                            'changes_requested',
                            'approved_for_merge',
                            'done',
                            'cancelled'
                        )
                    ),
                ADD CONSTRAINT roadmap_tasks_reported_state_check
                    CHECK (
                        reported_state IS NULL
                        OR reported_state
                            ~ '^[a-z][a-z0-9_]{1,119}$'
                    ),
                ADD CONSTRAINT roadmap_tasks_observed_state_check
                    CHECK (
                        observed_state IS NULL
                        OR observed_state
                            ~ '^[a-z][a-z0-9_]{1,119}$'
                    ),
                ADD CONSTRAINT roadmap_tasks_actual_state_check
                    CHECK (
                        actual_state IN (
                            'unverified',
                            'observed',
                            'verified',
                            'rejected'
                        )
                    )
            SQL);
    }

    /**
     * Remove only the fields introduced by this migration.
     *
     * Production deployments should prefer a forward migration after ticket
     * state has been recorded because rolling back removes those new values.
     */
    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE roadmap_tasks
                DROP CONSTRAINT IF EXISTS
                    roadmap_tasks_status_check,
                DROP CONSTRAINT IF EXISTS
                    roadmap_tasks_desired_state_check,
                DROP CONSTRAINT IF EXISTS
                    roadmap_tasks_reported_state_check,
                DROP CONSTRAINT IF EXISTS
                    roadmap_tasks_observed_state_check,
                DROP CONSTRAINT IF EXISTS
                    roadmap_tasks_actual_state_check
            SQL);

        Schema::table('roadmap_tasks', function (Blueprint $table): void {
            $table->dropIndex(
                'roadmap_tasks_roadmap_status_ready_index',
            );

            $table->dropColumn([
                'status',
                'desired_state',
                'reported_state',
                'observed_state',
                'actual_state',
                'status_changed_at',
                'ready_at',
            ]);
        });
    }
};
