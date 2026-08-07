<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create workflow-instance state and append-only transition history.
     */
    public function up(): void
    {
        Schema::create(
            'workflow_instances',
            function (Blueprint $table): void {
                // Table ownership: Workflows module.
                $table->id();

                /*
                 * Every workflow instance belongs to exactly one project.
                 *
                 * Project deletion is restricted because workflow history is
                 * operational evidence and must not disappear implicitly.
                 */
                $table->foreignId('project_id')
                    ->constrained()
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();

                /*
                 * Bind permanently to one immutable definition version.
                 *
                 * Newer versions may be published, but an existing instance
                 * must continue using this exact definition row.
                 */
                $table->foreignId('workflow_definition_id')
                    ->constrained()
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();

                $table->string('current_state', 100);

                /*
                 * Sequence zero means the instance has not committed a
                 * transition and remains at its definition's initial state.
                 */
                $table->unsignedBigInteger('transition_sequence')
                    ->default(0);

                /*
                 * Set when a transition enters one of the definition's
                 * terminal states.
                 */
                $table->timestampTz('completed_at')->nullable();

                $table->timestampsTz();

                $table->index(
                    ['project_id', 'current_state'],
                    'workflow_instances_project_state_index',
                );

                $table->index(
                    'workflow_definition_id',
                    'workflow_instances_definition_index',
                );
            },
        );

        Schema::create(
            'workflow_transitions',
            function (Blueprint $table): void {
                // Table ownership: Workflows module.
                $table->id();

                /*
                 * Transition history is retained with its workflow instance.
                 * Instances therefore cannot be deleted after transitions
                 * have been committed.
                 */
                $table->foreignId('workflow_instance_id')
                    ->constrained()
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();

                $table->unsignedBigInteger('sequence');

                $table->string('name', 120);
                $table->string('from_state', 100);
                $table->string('to_state', 100);
                $table->string('guard', 120)->nullable();

                /*
                 * Transition rows are append-only and do not have updated_at.
                 */
                $table->timestampTz('created_at')->useCurrent();

                $table->unique(
                    ['workflow_instance_id', 'sequence'],
                    'workflow_transitions_instance_sequence_unique',
                );

                $table->index(
                    ['workflow_instance_id', 'created_at'],
                    'workflow_transitions_instance_created_index',
                );
            },
        );

        DB::statement(<<<'SQL'
            ALTER TABLE workflow_instances
                ADD CONSTRAINT workflow_instances_current_state_check
                    CHECK (
                        current_state
                        ~ '^[a-z][a-z0-9_]{1,99}$'
                    ),
                ADD CONSTRAINT workflow_instances_sequence_check
                    CHECK (transition_sequence >= 0)
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE workflow_transitions
                ADD CONSTRAINT workflow_transitions_sequence_check
                    CHECK (sequence >= 1),
                ADD CONSTRAINT workflow_transitions_name_check
                    CHECK (
                        name
                        ~ '^[a-z][a-z0-9_.-]{1,119}$'
                    ),
                ADD CONSTRAINT workflow_transitions_from_state_check
                    CHECK (
                        from_state
                        ~ '^[a-z][a-z0-9_]{1,99}$'
                    ),
                ADD CONSTRAINT workflow_transitions_to_state_check
                    CHECK (
                        to_state
                        ~ '^[a-z][a-z0-9_]{1,99}$'
                    ),
                ADD CONSTRAINT workflow_transitions_guard_check
                    CHECK (
                        guard IS NULL
                        OR guard
                            ~ '^[a-z][a-z0-9_.-]{1,119}$'
                    )
            SQL);

        /*
         * Transition history is durable operational evidence. Protect it at
         * the PostgreSQL boundary so query-builder or raw-SQL updates cannot
         * bypass Eloquent model hooks.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION
                public.aios_reject_workflow_transition_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION USING
                    MESSAGE = 'workflow_transitions is append-only',
                    ERRCODE = '55000';
            END;
            $$;
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER workflow_transitions_reject_update_delete
            BEFORE UPDATE OR DELETE ON workflow_transitions
            FOR EACH ROW
            EXECUTE FUNCTION
                public.aios_reject_workflow_transition_mutation()
            SQL);
    }

    /**
     * Remove workflow-instance and transition storage.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_transitions');
        Schema::dropIfExists('workflow_instances');

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS
                public.aios_reject_workflow_transition_mutation()
            SQL);
    }
};
