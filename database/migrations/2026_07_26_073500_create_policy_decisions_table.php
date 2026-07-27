<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the append-only policy-decision history store.
     */
    public function up(): void
    {
        /*
         * The composite key lets PostgreSQL prove that a decision and execution
         * belong to the same project, even when writes bypass Eloquent.
         */
        Schema::table('executions', function (Blueprint $table): void {
            $table->unique(
                ['id', 'project_id'],
                'executions_id_project_unique',
            );
        });

        Schema::create('policy_decisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignId('project_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->ulid('execution_id');
            $table->string('decision_type', 80);
            $table->unsignedSmallInteger('policy_version');

            $table->string('requested_reasoning_level', 20);
            $table->string('requested_reasoning_source', 80);
            $table->string('effective_reasoning_level', 20);
            $table->string('reasoning_resolution_source', 80);
            $table->text('reasoning_escalation_reason')->nullable();
            $table->jsonb('reasoning_escalation_reasons');

            /*
             * The snapshot contains only normalized enum values and the project
             * configuration revision. Credentials and arbitrary prompt text are
             * explicitly outside this contract.
             */
            $table->jsonb('input_snapshot');
            $table->string('input_fingerprint', 64);

            /*
             * Policy decisions are immutable history and therefore have no
             * updated_at column.
             */
            $table->timestampTz('created_at', precision: 6)->useCurrent();

            $table->unique(
                ['execution_id', 'decision_type'],
                'policy_decisions_execution_type_unique',
            );

            $table->index(
                ['project_id', 'created_at'],
                'policy_decisions_project_created_index',
            );

            $table->index(
                [
                    'reasoning_resolution_source',
                    'effective_reasoning_level',
                ],
                'policy_decisions_source_level_index',
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE policy_decisions
                ADD CONSTRAINT policy_decisions_execution_project_foreign
                    FOREIGN KEY (
                        execution_id,
                        project_id
                    )
                    REFERENCES executions (
                        id,
                        project_id
                    )
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE policy_decisions
                ADD CONSTRAINT policy_decisions_type_check
                    CHECK (decision_type = 'reasoning_resolution'),
                ADD CONSTRAINT policy_decisions_version_check
                    CHECK (policy_version >= 1),
                ADD CONSTRAINT policy_decisions_requested_level_check
                    CHECK (
                        requested_reasoning_level IN (
                            'low',
                            'medium',
                            'high'
                        )
                    ),
                ADD CONSTRAINT policy_decisions_effective_level_check
                    CHECK (
                        effective_reasoning_level IN (
                            'low',
                            'medium',
                            'high'
                        )
                    ),
                ADD CONSTRAINT policy_decisions_requested_source_check
                    CHECK (
                        requested_reasoning_source IN (
                            'explicit_approved_ticket',
                            'task_type_default',
                            'agent_role_default',
                            'project_default',
                            'system_fallback'
                        )
                    ),
                ADD CONSTRAINT policy_decisions_resolution_source_check
                    CHECK (
                        reasoning_resolution_source IN (
                            'explicit_approved_ticket',
                            'policy_required_minimum',
                            'task_type_default',
                            'agent_role_default',
                            'project_default',
                            'system_fallback',
                            'mandatory_escalation'
                        )
                    ),
                ADD CONSTRAINT policy_decisions_escalation_reasons_check
                    CHECK (
                        jsonb_typeof(reasoning_escalation_reasons) = 'array'
                        AND reasoning_escalation_reasons <@ '[
                            "security",
                            "authorization",
                            "privacy",
                            "money",
                            "critical_business_data",
                            "destructive_change",
                            "architecture_conflict",
                            "non_deterministic_failure",
                            "production_reliability",
                            "final_qa",
                            "merge_decision"
                        ]'::jsonb
                    ),
                ADD CONSTRAINT policy_decisions_escalation_consistency_check
                    CHECK (
                        (
                            reasoning_resolution_source = 'mandatory_escalation'
                            AND effective_reasoning_level = 'high'
                            AND jsonb_array_length(
                                reasoning_escalation_reasons
                            ) >= 1
                            AND reasoning_escalation_reason IS NOT NULL
                        )
                        OR (
                            reasoning_resolution_source <> 'mandatory_escalation'
                            AND jsonb_array_length(
                                reasoning_escalation_reasons
                            ) = 0
                        )
                    ),
                ADD CONSTRAINT policy_decisions_input_shape_check
                    CHECK (jsonb_typeof(input_snapshot) = 'object'),
                ADD CONSTRAINT policy_decisions_fingerprint_check
                    CHECK (input_fingerprint ~ '^[a-f0-9]{64}$')
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION
                public.aios_reject_policy_decision_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION USING
                    MESSAGE = 'policy_decisions is append-only',
                    ERRCODE = '55000';
            END;
            $$;
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER policy_decisions_reject_update_delete
            BEFORE UPDATE OR DELETE
                ON policy_decisions
            FOR EACH ROW
            EXECUTE FUNCTION aios_reject_policy_decision_mutation()
            SQL);
    }

    /**
     * Remove policy decisions during an explicitly approved rollback.
     */
    public function down(): void
    {
        Schema::dropIfExists('policy_decisions');

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS
                public.aios_reject_policy_decision_mutation()
            SQL);

        Schema::table('executions', function (Blueprint $table): void {
            $table->dropUnique('executions_id_project_unique');
        });
    }
};
