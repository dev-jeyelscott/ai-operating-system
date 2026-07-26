<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create execution summaries and their ordered attempt history.
     */
    public function up(): void
    {
        /*
         * PostgreSQL requires the referenced column combination to be unique
         * before a composite foreign key can enforce project ownership.
         */
        Schema::table(
            'workflow_instances',
            function (Blueprint $table): void {
                $table->unique(
                    ['id', 'project_id'],
                    'workflow_instances_id_project_unique',
                );
            },
        );

        Schema::create('executions', function (Blueprint $table): void {
            /*
             * Public execution identifiers also appear in event envelopes,
             * audit records, logs, artifacts, and future external references.
             */
            $table->ulid('id')->primary();

            /*
             * Execution history is operational evidence. Project deletion must
             * never remove it implicitly.
             */
            $table->foreignId('project_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            /*
             * A workflow association is optional because some future manual or
             * operational executions may exist without a workflow instance.
             *
             * The composite foreign key below guarantees that an associated
             * workflow instance belongs to the same project.
             */
            $table->unsignedBigInteger('workflow_instance_id')->nullable();

            $table->string('capability', 120);
            $table->string('logical_role', 120)->nullable();
            $table->string('status', 40)->default('queued');

            /*
             * This is the reasoning level requested for the logical execution.
             * Each attempt separately snapshots its effective resolution.
             */
            $table->string('requested_reasoning_level', 20);

            /*
             * AIOS-055 will update this under a row lock when it creates an
             * attempt. AIOS-052 only establishes the durable counter.
             */
            $table->unsignedInteger('attempt_count')->default(0);

            /*
             * Correlation joins executions to audit events and domain events.
             * The caller builds the idempotency key from stable business input.
             */
            $table->ulid('correlation_id');
            $table->string('idempotency_key', 191);

            $table->timestampTz(
                'started_at',
                precision: 6,
            )->nullable();

            $table->timestampTz(
                'finished_at',
                precision: 6,
            )->nullable();

            $table->timestampsTz(precision: 6);

            $table->unique(
                ['project_id', 'idempotency_key'],
                'executions_project_idempotency_unique',
            );

            $table->index(
                ['project_id', 'status', 'created_at'],
                'executions_project_status_created_index',
            );

            $table->index(
                ['workflow_instance_id', 'status'],
                'executions_workflow_status_index',
            );

            $table->index(
                'correlation_id',
                'executions_correlation_id_index',
            );
        });

        /*
         * This prevents an execution owned by Project A from referencing a
         * workflow instance owned by Project B, including through raw SQL.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE executions
                ADD CONSTRAINT executions_workflow_project_foreign
                    FOREIGN KEY (
                        workflow_instance_id,
                        project_id
                    )
                    REFERENCES workflow_instances (
                        id,
                        project_id
                    )
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT
            SQL);

        Schema::create(
            'execution_attempts',
            function (Blueprint $table): void {
                $table->id();

                /*
                 * Attempts are operational history and must not disappear
                 * through a cascading parent deletion.
                 */
                $table->foreignUlid('execution_id')
                    ->constrained()
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();

                $table->unsignedInteger('attempt_number');
                $table->string('status', 40)->default('queued');

                /*
                 * Persist the exact provider and reasoning decision used by
                 * this attempt. Retry or fallback must create another attempt,
                 * not overwrite this provider snapshot.
                 */
                $table->string('execution_provider', 120);
                $table->string('model_identifier', 191)->nullable();

                $table->string(
                    'requested_reasoning_level',
                    20,
                );

                $table->string(
                    'effective_reasoning_level',
                    20,
                );

                $table->string(
                    'reasoning_resolution_source',
                    120,
                );

                $table->text(
                    'reasoning_escalation_reason',
                )->nullable();

                /*
                 * Simulation metadata remains nullable for human and future
                 * real-provider executions.
                 */
                $table->string('simulation_mode', 120)->nullable();
                $table->string('simulation_seed', 191)->nullable();

                /*
                 * Keep claimed, observed, and authoritative states separate.
                 * A simulated completion should normally remain unverified.
                 */
                $table->string('reported_state', 120)->nullable();
                $table->string('observed_state', 120)->nullable();
                $table->string('actual_state', 120)->nullable();

                $table->decimal(
                    'confidence',
                    total: 5,
                    places: 4,
                )->nullable();

                /*
                 * Fixed-precision decimal values avoid floating-point rounding.
                 * Currency is nullable until a provider returns a cost value.
                 */
                $table->decimal(
                    'estimated_cost',
                    total: 20,
                    places: 8,
                )->nullable();

                $table->decimal(
                    'actual_cost',
                    total: 20,
                    places: 8,
                )->nullable();

                $table->char('cost_currency', 3)->nullable();

                /*
                 * Persist redacted errors only. Raw credentials, prompts, HTTP
                 * headers, or provider payloads must never enter these fields.
                 */
                $table->string('error_code', 120)->nullable();
                $table->text('error_message')->nullable();

                $table->timestampTz(
                    'started_at',
                    precision: 6,
                )->nullable();

                $table->timestampTz(
                    'finished_at',
                    precision: 6,
                )->nullable();

                $table->timestampsTz(precision: 6);

                $table->unique(
                    ['execution_id', 'attempt_number'],
                    'execution_attempts_execution_number_unique',
                );

                $table->index(
                    ['execution_id', 'status'],
                    'execution_attempts_execution_status_index',
                );

                $table->index(
                    [
                        'execution_provider',
                        'status',
                        'created_at',
                    ],
                    'execution_attempts_provider_status_created_index',
                );
            },
        );

        DB::statement(<<<'SQL'
            ALTER TABLE executions
                ADD CONSTRAINT executions_status_check
                    CHECK (
                        status IN (
                            'queued',
                            'running',
                            'waiting_for_approval',
                            'waiting_for_evidence',
                            'blocked',
                            'retry_scheduled',
                            'completed',
                            'failed',
                            'cancelled'
                        )
                    ),
                ADD CONSTRAINT executions_capability_check
                    CHECK (
                        capability
                            ~ '^[a-z][a-z0-9_.-]{1,119}$'
                    ),
                ADD CONSTRAINT executions_logical_role_check
                    CHECK (
                        logical_role IS NULL
                        OR logical_role
                            ~ '^[a-z][a-z0-9_.-]{1,119}$'
                    ),
                ADD CONSTRAINT executions_reasoning_check
                    CHECK (
                        requested_reasoning_level IN (
                            'low',
                            'medium',
                            'high'
                        )
                    ),
                ADD CONSTRAINT executions_attempt_count_check
                    CHECK (attempt_count >= 0),
                ADD CONSTRAINT executions_idempotency_key_check
                    CHECK (btrim(idempotency_key) <> ''),
                ADD CONSTRAINT executions_timing_check
                    CHECK (
                        finished_at IS NULL
                        OR started_at IS NULL
                        OR finished_at >= started_at
                    )
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE execution_attempts
                ADD CONSTRAINT execution_attempts_number_check
                    CHECK (attempt_number >= 1),
                ADD CONSTRAINT execution_attempts_status_check
                    CHECK (
                        status IN (
                            'queued',
                            'running',
                            'completed',
                            'failed',
                            'timed_out',
                            'cancelled'
                        )
                    ),
                ADD CONSTRAINT execution_attempts_provider_check
                    CHECK (
                        execution_provider
                            ~ '^[a-z][a-z0-9_.-]{1,119}$'
                    ),
                ADD CONSTRAINT execution_attempts_reasoning_check
                    CHECK (
                        requested_reasoning_level IN (
                            'low',
                            'medium',
                            'high'
                        )
                        AND effective_reasoning_level IN (
                            'low',
                            'medium',
                            'high'
                        )
                    ),
                ADD CONSTRAINT execution_attempts_resolution_source_check
                    CHECK (
                        reasoning_resolution_source
                            ~ '^[a-z][a-z0-9_.-]{1,119}$'
                    ),
                ADD CONSTRAINT execution_attempts_simulation_mode_check
                    CHECK (
                        simulation_mode IS NULL
                        OR simulation_mode
                            ~ '^[a-z][a-z0-9_.-]{1,119}$'
                    ),
                ADD CONSTRAINT execution_attempts_reported_state_check
                    CHECK (
                        reported_state IS NULL
                        OR reported_state
                            ~ '^[a-z][a-z0-9_]{1,119}$'
                    ),
                ADD CONSTRAINT execution_attempts_observed_state_check
                    CHECK (
                        observed_state IS NULL
                        OR observed_state
                            ~ '^[a-z][a-z0-9_]{1,119}$'
                    ),
                ADD CONSTRAINT execution_attempts_actual_state_check
                    CHECK (
                        actual_state IS NULL
                        OR actual_state
                            ~ '^[a-z][a-z0-9_]{1,119}$'
                    ),
                ADD CONSTRAINT execution_attempts_confidence_check
                    CHECK (
                        confidence IS NULL
                        OR (
                            confidence >= 0
                            AND confidence <= 1
                        )
                    ),
                ADD CONSTRAINT execution_attempts_estimated_cost_check
                    CHECK (
                        estimated_cost IS NULL
                        OR estimated_cost >= 0
                    ),
                ADD CONSTRAINT execution_attempts_actual_cost_check
                    CHECK (
                        actual_cost IS NULL
                        OR actual_cost >= 0
                    ),
                ADD CONSTRAINT execution_attempts_currency_check
                    CHECK (
                        cost_currency IS NULL
                        OR cost_currency ~ '^[A-Z]{3}$'
                    ),
                ADD CONSTRAINT execution_attempts_error_code_check
                    CHECK (
                        error_code IS NULL
                        OR error_code
                            ~ '^[a-z][a-z0-9_.-]{1,119}$'
                    ),
                ADD CONSTRAINT execution_attempts_timing_check
                    CHECK (
                        finished_at IS NULL
                        OR started_at IS NULL
                        OR finished_at >= started_at
                    )
            SQL);
    }

    /**
     * Remove execution storage during an explicitly approved rollback.
     */
    public function down(): void
    {
        Schema::dropIfExists('execution_attempts');
        Schema::dropIfExists('executions');

        Schema::table(
            'workflow_instances',
            function (Blueprint $table): void {
                $table->dropUnique(
                    'workflow_instances_id_project_unique',
                );
            },
        );
    }
};
