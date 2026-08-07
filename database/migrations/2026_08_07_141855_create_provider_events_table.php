<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add ordered normalized provider events without persisting raw protocol
     * messages.
     */
    public function up(): void
    {
        Schema::create(
            'provider_events',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();

                $table
                    ->foreignUlid('provider_session_id')
                    ->constrained('provider_sessions')
                    ->restrictOnDelete();

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

                $table->unsignedBigInteger('sequence');

                $table->string('event_type', 100);

                $table->string('provider_method', 191);

                $table
                    ->string('provider_request_id', 191)
                    ->nullable();

                $table
                    ->string('provider_thread_id', 191)
                    ->nullable();

                $table
                    ->string('provider_turn_id', 191)
                    ->nullable();

                $table
                    ->string('provider_item_id', 191)
                    ->nullable();

                $table
                    ->string('provider_cursor', 191)
                    ->nullable();

                /*
                 * Identity and content fingerprints support duplicate detection
                 * without persisting the unredacted protocol message.
                 */
                $table->char(
                    'provider_identity_sha256',
                    64,
                );

                $table->char(
                    'payload_fingerprint_sha256',
                    64,
                );

                $table->jsonb('payload');

                $table
                    ->timestampTz('occurred_at', 6);

                $table
                    ->timestampTz('created_at', 6)
                    ->useCurrent();

                $table->unique(
                    [
                        'provider_session_id',
                        'sequence',
                    ],
                    'provider_events_session_sequence_unique',
                );

                $table->unique(
                    [
                        'provider_session_id',
                        'provider_identity_sha256',
                    ],
                    'provider_events_session_identity_unique',
                );

                $table->index(
                    [
                        'execution_id',
                        'sequence',
                    ],
                    'provider_events_execution_sequence_idx',
                );

                $table->index(
                    [
                        'organization_id',
                        'project_id',
                        'event_type',
                    ],
                    'provider_events_project_type_idx',
                );

                $table->index(
                    [
                        'provider_thread_id',
                        'provider_turn_id',
                    ],
                    'provider_events_thread_turn_idx',
                );
            },
        );
    }

    /**
     * Preserve normalized provider history during application rollback.
     */
    public function down(): void
    {
        // Intentionally irreversible to prevent loss of execution evidence.
    }
};
