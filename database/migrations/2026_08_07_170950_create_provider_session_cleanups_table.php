<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create idempotent per-resource cleanup records for provider recovery.
     */
    public function up(): void
    {
        Schema::create(
            'provider_session_cleanups',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();

                $table
                    ->foreignUlid('provider_session_id')
                    ->constrained('provider_sessions')
                    ->restrictOnDelete();

                $table->string('resource', 50);
                $table->string('status', 50);

                $table
                    ->unsignedInteger('attempt_count')
                    ->default(0);

                $table
                    ->string('last_error_code', 120)
                    ->nullable();

                $table
                    ->text('last_error_message')
                    ->nullable();

                $table
                    ->timestampTz('started_at', 6)
                    ->nullable();

                $table
                    ->timestampTz('completed_at', 6)
                    ->nullable();

                $table->timestampsTz(6);

                $table->unique(
                    [
                        'provider_session_id',
                        'resource',
                    ],
                    'provider_session_cleanup_resource_unique',
                );

                $table->index(
                    [
                        'status',
                        'updated_at',
                    ],
                    'provider_session_cleanup_status_idx',
                );
            },
        );
    }

    /**
     * Preserve cleanup and recovery evidence during application rollback.
     */
    public function down(): void
    {
        // Intentionally irreversible to prevent loss of recovery evidence.
    }
};
