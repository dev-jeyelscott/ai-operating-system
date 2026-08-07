<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add durable provider-session lineage without modifying historical
     * execution or simulation rows.
     */
    public function up(): void
    {
        Schema::create(
            'provider_sessions',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();

                $table
                    ->foreignId('organization_id')
                    ->constrained()
                    ->restrictOnDelete();

                $table
                    ->foreignId('project_id')
                    ->constrained()
                    ->restrictOnDelete();

                $table
                    ->foreignUlid('execution_id')
                    ->constrained('executions')
                    ->restrictOnDelete();

                $table
                    ->foreignId('execution_attempt_id')
                    ->constrained('execution_attempts')
                    ->restrictOnDelete();

                $table->string('provider', 100);

                $table
                    ->string('binary_version', 100)
                    ->nullable();

                $table
                    ->string('protocol_version', 100);

                $table
                    ->char('protocol_schema_fingerprint', 64);

                $table
                    ->string('model_identifier', 191)
                    ->nullable();

                $table
                    ->string('sandbox_profile', 100);

                $table
                    ->string('network_policy', 100);

                $table
                    ->string('provider_thread_id', 191)
                    ->nullable();

                $table
                    ->string('provider_turn_id', 191)
                    ->nullable();

                $table
                    ->unsignedBigInteger('runtime_process_id')
                    ->nullable();

                $table
                    ->string('status', 50)
                    ->default('active');

                $table
                    ->unsignedBigInteger('last_provider_sequence')
                    ->default(0);

                $table
                    ->string('last_provider_cursor', 191)
                    ->nullable();

                $table
                    ->timestampTz('process_started_at', 6)
                    ->nullable();

                $table
                    ->timestampTz('initialized_at', 6)
                    ->nullable();

                $table
                    ->timestampTz('heartbeat_at', 6)
                    ->nullable();

                $table
                    ->timestampTz('terminal_at', 6)
                    ->nullable();

                $table
                    ->string('terminal_status', 50)
                    ->nullable();

                $table
                    ->string('terminal_code', 100)
                    ->nullable();

                $table
                    ->timestampTz('cancellation_requested_at', 6)
                    ->nullable();

                $table
                    ->string('cleanup_status', 50)
                    ->nullable();

                $table
                    ->timestampTz('transcript_truncated_at', 6)
                    ->nullable();

                $table->timestampsTz(6);

                /*
                 * Exactly one provider process session belongs to one immutable
                 * execution attempt in the initial architecture.
                 */
                $table->unique(
                    'execution_attempt_id',
                    'provider_sessions_attempt_unique',
                );

                $table->index(
                    [
                        'organization_id',
                        'project_id',
                        'status',
                    ],
                    'provider_sessions_project_status_idx',
                );

                $table->index(
                    [
                        'status',
                        'heartbeat_at',
                    ],
                    'provider_sessions_heartbeat_idx',
                );

                $table->index(
                    [
                        'execution_id',
                        'created_at',
                    ],
                    'provider_sessions_execution_timeline_idx',
                );
            },
        );
    }

    /**
     * Preserve provider execution provenance during application rollback.
     *
     * Rolling application code back must never erase real provider history.
     */
    public function down(): void
    {
        // Intentionally irreversible to prevent loss of provider lineage.
    }
};
