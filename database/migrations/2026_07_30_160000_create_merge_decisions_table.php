<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create append-only simulated merge-decision history.
     */
    public function up(): void
    {
        Schema::create('merge_decisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignId('project_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('roadmap_task_id')
                ->constrained('roadmap_tasks')
                ->restrictOnDelete();

            $table->foreignUlid('qa_assessment_id')
                ->constrained('qa_assessments')
                ->restrictOnDelete();

            $table->foreignId('actor_user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('action', 32);

            $table->text('reason')->nullable();

            /*
             * The raw caller key is never persisted. The project-scoped hash
             * protects replay without retaining potentially sensitive input.
             */
            $table->char('idempotency_key_hash', 64);

            /*
             * Detects reuse of one idempotency key with different command data.
             */
            $table->char('request_fingerprint', 64);

            $table->string('correlation_id', 128);

            $table->string('causation_id', 128)->nullable();

            $table->string('assessment_decision', 64);

            $table->char('assessment_fingerprint', 64);

            $table->string('ticket_status_before', 32);

            $table->string('ticket_status_after', 32);

            /*
             * Terminal decisions store "T". Non-terminal decisions store null.
             * PostgreSQL permits multiple null values in a unique constraint,
             * but only one terminal marker for each assessment.
             */
            $table->char('terminal_marker', 1)->nullable();

            $table->boolean('simulated')->default(true);

            $table->string('actual_state', 32)
                ->default('unverified');

            $table->timestampTz('decided_at');

            $table->timestampsTz();

            $table->unique(
                ['project_id', 'idempotency_key_hash'],
                'merge_decisions_project_idempotency_unique',
            );

            $table->unique(
                ['qa_assessment_id', 'terminal_marker'],
                'merge_decisions_terminal_unique',
            );

            $table->index(
                ['qa_assessment_id', 'decided_at'],
                'merge_decisions_assessment_time_index',
            );

            $table->index(
                ['roadmap_task_id', 'decided_at'],
                'merge_decisions_ticket_time_index',
            );
        });
    }

    /**
     * Remove the table during an intentional pre-production rollback.
     */
    public function down(): void
    {
        Schema::dropIfExists('merge_decisions');
    }
};
