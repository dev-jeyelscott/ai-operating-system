<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create durable, tenant-scoped approval requests and decisions.
     */
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignId('project_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('workflow_instance_id')
                ->nullable()
                ->constrained('workflow_instances')
                ->restrictOnDelete();

            $table->foreignUlid('execution_id')
                ->nullable()
                ->constrained('executions')
                ->restrictOnDelete();

            $table->string('type', 64);
            $table->string('status', 32)->default('pending');

            /*
             * User references may become null when an account is removed.
             * Approval history, fingerprints, audit events, and domain events
             * remain available after that deletion.
             */
            $table->foreignId('requested_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('decided_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
             * Request and decision keys are independently idempotent.
             * PostgreSQL permits multiple null values in the decision-key
             * uniqueness constraint while an approval is still pending.
             */
            $table->string('request_idempotency_key', 191);
            $table->char('request_fingerprint', 64);

            $table->string('decision_idempotency_key', 191)
                ->nullable();

            $table->char('decision_fingerprint', 64)
                ->nullable();

            $table->jsonb('request_payload');

            $table->text('decision_reason')->nullable();

            $table->timestampTz('requested_at', 6);
            $table->timestampTz('expires_at', 6)->nullable();
            $table->timestampTz('decided_at', 6)->nullable();

            $table->timestampsTz(6);

            $table->unique(
                ['project_id', 'request_idempotency_key'],
                'approvals_project_request_key_unique',
            );

            $table->unique(
                ['project_id', 'decision_idempotency_key'],
                'approvals_project_decision_key_unique',
            );

            $table->index(
                ['project_id', 'status', 'expires_at'],
                'approvals_project_status_expiry_index',
            );

            $table->index(
                ['workflow_instance_id', 'status'],
                'approvals_workflow_status_index',
            );

            $table->index(
                ['execution_id', 'status'],
                'approvals_execution_status_index',
            );
        });
    }

    /**
     * Remove the approvals table during a local rollback.
     */
    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};
