<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add immutable retry-policy snapshots and mutable recovery state.
     */
    public function up(): void
    {
        Schema::table('executions', function (Blueprint $table): void {
            /*
             * These values are snapshotted for each execution. A later project
             * configuration change must not alter an in-flight retry policy.
             */
            $table->unsignedSmallInteger('retry_limit')->default(3);
            $table->unsignedInteger('timeout_seconds')->default(900);

            $table->unsignedInteger(
                'retry_base_delay_seconds',
            )->default(30);

            $table->unsignedInteger(
                'retry_max_delay_seconds',
            )->default(900);

            $table->unsignedSmallInteger(
                'retry_jitter_percent',
            )->default(20);

            /*
             * next_attempt_at is populated only while an execution is waiting
             * for a retry. The recovery command promotes due records to queued.
             */
            $table->timestampTz(
                'next_attempt_at',
                precision: 6,
            )->nullable();

            /*
             * Cancellation is cooperative for active provider attempts.
             * Non-running executions can transition immediately to cancelled.
             */
            $table->timestampTz(
                'cancel_requested_at',
                precision: 6,
            )->nullable();

            $table->timestampTz(
                'cancelled_at',
                precision: 6,
            )->nullable();

            $table->text('cancellation_reason')->nullable();

            $table->index(
                ['status', 'next_attempt_at'],
                'executions_status_next_attempt_index',
            );

            $table->index(
                ['status', 'cancel_requested_at'],
                'executions_status_cancel_requested_index',
            );
        });

        Schema::table(
            'execution_attempts',
            function (Blueprint $table): void {
                /*
                 * deadline_at is an application-level deadline. heartbeat_at
                 * records liveness without rewriting immutable provider context.
                 */
                $table->timestampTz(
                    'deadline_at',
                    precision: 6,
                )->nullable();

                $table->timestampTz(
                    'heartbeat_at',
                    precision: 6,
                )->nullable();

                /*
                 * retryable and retry_delay_seconds explain why and when the
                 * manager decided to retry a failed or timed-out attempt.
                 */
                $table->boolean('retryable')->nullable();

                $table->unsignedInteger(
                    'retry_delay_seconds',
                )->nullable();

                $table->index(
                    ['status', 'deadline_at'],
                    'execution_attempts_status_deadline_index',
                );
            },
        );

        /*
         * Preserve currently running records during an upgrade by deriving
         * deadlines from their persisted start time and execution policy.
         */
        DB::statement(<<<'SQL'
            UPDATE execution_attempts AS attempt
            SET
                deadline_at = COALESCE(
                    attempt.started_at,
                    attempt.created_at
                ) + make_interval(secs => execution.timeout_seconds),
                heartbeat_at = COALESCE(
                    attempt.started_at,
                    attempt.created_at
                )
            FROM executions AS execution
            WHERE execution.id = attempt.execution_id
              AND attempt.status = 'running'
              AND attempt.deadline_at IS NULL
            SQL);

        /*
         * NOT VALID avoids validating existing rows while the constraint is
         * first installed. PostgreSQL validates each constraint afterward.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE executions
                ADD CONSTRAINT executions_retry_limit_check
                    CHECK (
                        retry_limit BETWEEN 0 AND 10
                    ) NOT VALID,
                ADD CONSTRAINT executions_timeout_seconds_check
                    CHECK (
                        timeout_seconds BETWEEN 1 AND 86400
                    ) NOT VALID,
                ADD CONSTRAINT executions_retry_backoff_check
                    CHECK (
                        retry_base_delay_seconds >= 1
                        AND retry_max_delay_seconds
                            >= retry_base_delay_seconds
                        AND retry_max_delay_seconds <= 86400
                    ) NOT VALID,
                ADD CONSTRAINT executions_retry_jitter_check
                    CHECK (
                        retry_jitter_percent BETWEEN 0 AND 100
                    ) NOT VALID,
                ADD CONSTRAINT executions_next_attempt_status_check
                    CHECK (
                        next_attempt_at IS NULL
                        OR status = 'retry_scheduled'
                    ) NOT VALID,
                ADD CONSTRAINT executions_cancellation_reason_check
                    CHECK (
                        cancellation_reason IS NULL
                        OR cancel_requested_at IS NOT NULL
                    ) NOT VALID,
                ADD CONSTRAINT executions_cancelled_state_check
                    CHECK (
                        cancelled_at IS NULL
                        OR status = 'cancelled'
                    ) NOT VALID,
                ADD CONSTRAINT executions_cancellation_timing_check
                    CHECK (
                        cancelled_at IS NULL
                        OR cancel_requested_at IS NULL
                        OR cancelled_at >= cancel_requested_at
                    ) NOT VALID
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE execution_attempts
                ADD CONSTRAINT execution_attempts_deadline_timing_check
                    CHECK (
                        deadline_at IS NULL
                        OR started_at IS NULL
                        OR deadline_at >= started_at
                    ) NOT VALID,
                ADD CONSTRAINT execution_attempts_heartbeat_timing_check
                    CHECK (
                        heartbeat_at IS NULL
                        OR started_at IS NULL
                        OR heartbeat_at >= started_at
                    ) NOT VALID,
                ADD CONSTRAINT execution_attempts_retry_metadata_check
                    CHECK (
                        (
                            retryable IS NULL
                            AND retry_delay_seconds IS NULL
                        )
                        OR status IN (
                            'failed',
                            'timed_out'
                        )
                    ) NOT VALID
            SQL);

        foreach (
            [
                'executions_retry_limit_check',
                'executions_timeout_seconds_check',
                'executions_retry_backoff_check',
                'executions_retry_jitter_check',
                'executions_next_attempt_status_check',
                'executions_cancellation_reason_check',
                'executions_cancelled_state_check',
                'executions_cancellation_timing_check',
            ] as $constraint
        ) {
            DB::statement(sprintf(
                'ALTER TABLE executions VALIDATE CONSTRAINT %s',
                $constraint,
            ));
        }

        foreach (
            [
                'execution_attempts_deadline_timing_check',
                'execution_attempts_heartbeat_timing_check',
                'execution_attempts_retry_metadata_check',
            ] as $constraint
        ) {
            DB::statement(sprintf(
                'ALTER TABLE execution_attempts VALIDATE CONSTRAINT %s',
                $constraint,
            ));
        }
    }

    /**
     * Remove resilience metadata during an explicitly approved rollback.
     */
    public function down(): void
    {
        foreach (
            [
                'executions_retry_limit_check',
                'executions_timeout_seconds_check',
                'executions_retry_backoff_check',
                'executions_retry_jitter_check',
                'executions_next_attempt_status_check',
                'executions_cancellation_reason_check',
                'executions_cancelled_state_check',
                'executions_cancellation_timing_check',
            ] as $constraint
        ) {
            DB::statement(sprintf(
                'ALTER TABLE executions DROP CONSTRAINT IF EXISTS %s',
                $constraint,
            ));
        }

        foreach (
            [
                'execution_attempts_deadline_timing_check',
                'execution_attempts_heartbeat_timing_check',
                'execution_attempts_retry_metadata_check',
            ] as $constraint
        ) {
            DB::statement(sprintf(
                'ALTER TABLE execution_attempts DROP CONSTRAINT IF EXISTS %s',
                $constraint,
            ));
        }

        Schema::table(
            'execution_attempts',
            function (Blueprint $table): void {
                $table->dropIndex(
                    'execution_attempts_status_deadline_index',
                );

                $table->dropColumn([
                    'deadline_at',
                    'heartbeat_at',
                    'retryable',
                    'retry_delay_seconds',
                ]);
            },
        );

        Schema::table('executions', function (Blueprint $table): void {
            $table->dropIndex(
                'executions_status_next_attempt_index',
            );

            $table->dropIndex(
                'executions_status_cancel_requested_index',
            );

            $table->dropColumn([
                'retry_limit',
                'timeout_seconds',
                'retry_base_delay_seconds',
                'retry_max_delay_seconds',
                'retry_jitter_percent',
                'next_attempt_at',
                'cancel_requested_at',
                'cancelled_at',
                'cancellation_reason',
            ]);
        });
    }
};
