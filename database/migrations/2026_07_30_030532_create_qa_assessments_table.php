<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the durable Layer 2 to Layer 3 orchestration and assessment record.
     */
    public function up(): void
    {
        Schema::create('qa_assessments', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignId('project_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('roadmap_task_id')
                ->constrained('roadmap_tasks')
                ->restrictOnDelete();

            $table->foreignUlid('implementation_execution_id')
                ->constrained('executions')
                ->restrictOnDelete();

            $table->foreignId('implementation_attempt_id')
                ->constrained('execution_attempts')
                ->restrictOnDelete();

            $table->foreignUlid('review_execution_id')
                ->constrained('executions')
                ->restrictOnDelete();

            $table->foreignId('review_attempt_id')
                ->nullable()
                ->constrained('execution_attempts')
                ->restrictOnDelete();

            $table->string('status', 32)
                ->default('queued');

            $table->string('simulation_scenario', 100)
                ->default('happy_path');

            $table->unsignedBigInteger('simulation_seed')
                ->default(106);

            $table->unsignedSmallInteger('result_schema_version')
                ->nullable();

            $table->string('decision', 50)
                ->nullable();

            $table->decimal('confidence', 5, 4)
                ->nullable();

            $table->string('target_branch', 255)
                ->nullable();

            $table->boolean('ticket_scope_satisfied')
                ->nullable();

            $table->boolean('acceptance_criteria_verified')
                ->nullable();

            $table->string('ci_status', 32)
                ->nullable();

            $table->string('test_status', 32)
                ->nullable();

            $table->string('architecture_status', 32)
                ->nullable();

            $table->string('security_status', 32)
                ->nullable();

            $table->string('database_impact', 32)
                ->nullable();

            $table->string('performance_impact', 32)
                ->nullable();

            $table->string('regression_risk', 32)
                ->nullable();

            $table->string('rollback_complexity', 32)
                ->nullable();

            $table->jsonb('unresolved_findings')
                ->nullable();

            $table->jsonb('merge_risks')
                ->nullable();

            $table->text('recommendation')
                ->nullable();

            $table->jsonb('evidence_ids')
                ->nullable();

            $table->char('canonical_assessment_fingerprint', 64)
                ->nullable();

            $table->timestampTz('created_at', 6)
                ->useCurrent();

            $table->timestampTz('updated_at', 6)
                ->useCurrent();

            /*
             * One implementation execution may have only one active Layer 3
             * assessment for the same ticket. Provider retries remain attempts
             * under the same review execution and assessment.
             */
            $table->unique(
                [
                    'roadmap_task_id',
                    'implementation_execution_id',
                ],
                'qa_assessments_ticket_implementation_unique',
            );

            $table->unique(
                'review_execution_id',
                'qa_assessments_review_execution_unique',
            );

            $table->index(
                [
                    'project_id',
                    'status',
                    'created_at',
                ],
                'qa_assessments_project_status_created_index',
            );

            $table->index(
                [
                    'project_id',
                    'roadmap_task_id',
                ],
                'qa_assessments_project_ticket_index',
            );
        });
    }

    /**
     * Preserve QA execution provenance after this migration is deployed.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'QA assessment lineage is forward-only and must not be removed.',
        );
    }
};
