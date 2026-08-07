<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the durable lease history used by atomic ticket selection.
     */
    public function up(): void
    {
        Schema::create(
            'ticket_execution_leases',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();

                $table->foreignId('project_id')
                    ->constrained()
                    ->restrictOnDelete();

                $table->foreignId('roadmap_task_id')
                    ->constrained('roadmap_tasks')
                    ->restrictOnDelete();

                $table->foreignUlid('execution_id')
                    ->constrained('executions')
                    ->restrictOnDelete();

                $table->string('owner', 100);
                $table->timestampTz('acquired_at', 6);
                $table->timestampTz('expires_at', 6);
                $table->timestampTz('heartbeat_at', 6);
                $table->timestampTz('released_at', 6)->nullable();
                $table->string('release_reason', 100)->nullable();
                $table->timestampsTz(6);

                /*
                 * One logical execution owns one stable ticket-selection result.
                 * Replaying the execution returns the original lease.
                 */
                $table->unique(
                    'execution_id',
                    'ticket_execution_leases_execution_id_unique',
                );

                /*
                 * Supports project queue, recovery, and expiry queries.
                 */
                $table->index(
                    ['project_id', 'released_at', 'expires_at'],
                    'ticket_execution_leases_project_active_index',
                );

                $table->index(
                    'expires_at',
                    'ticket_execution_leases_expires_at_index',
                );
            },
        );

        /*
         * Final database-level guarantee that a ticket cannot have two
         * unreleased leases.
         *
         * Expired but unreleased leases remain active until AIOS-093 confirms
         * the previous execution is no longer alive.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ticket_execution_leases_one_active_per_ticket
            ON ticket_execution_leases (roadmap_task_id)
            WHERE released_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ticket_execution_leases
            ADD CONSTRAINT ticket_execution_leases_valid_expiry_check
            CHECK (expires_at > acquired_at)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ticket_execution_leases
            ADD CONSTRAINT ticket_execution_leases_valid_heartbeat_check
            CHECK (
                heartbeat_at >= acquired_at
                AND heartbeat_at <= expires_at
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ticket_execution_leases
            ADD CONSTRAINT ticket_execution_leases_release_pair_check
            CHECK (
                (released_at IS NULL AND release_reason IS NULL)
                OR
                (released_at IS NOT NULL AND release_reason IS NOT NULL)
            )
        SQL);
    }

    /**
     * Remove the lease table when rolling back this additive migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_execution_leases');
    }
};
