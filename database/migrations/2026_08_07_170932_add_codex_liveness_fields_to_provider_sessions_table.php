<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add nullable Codex liveness and recovery metadata without rewriting
     * historical provider-session rows.
     */
    public function up(): void
    {
        Schema::table(
            'provider_sessions',
            function (Blueprint $table): void {
                $table
                    ->jsonb('timeout_policy')
                    ->nullable();

                $table
                    ->string('lifecycle_phase', 50)
                    ->nullable();

                $table
                    ->timestampTz('phase_started_at', 6)
                    ->nullable();

                $table
                    ->timestampTz('phase_deadline_at', 6)
                    ->nullable();

                $table
                    ->timestampTz('last_provider_message_at', 6)
                    ->nullable();

                $table
                    ->string('runtime_host_id', 191)
                    ->nullable();

                $table
                    ->char('runtime_identity_fingerprint', 64)
                    ->nullable();

                /*
                 * Runtime paths are encrypted by the Eloquent model. They are
                 * persisted only so recovery can safely remove attempt-local
                 * runtime material after a worker restart.
                 */
                $table
                    ->text('workspace_path')
                    ->nullable();

                $table
                    ->text('codex_home_path')
                    ->nullable();

                $table
                    ->text('temporary_path')
                    ->nullable();

                $table
                    ->timestampTz('recovery_required_at', 6)
                    ->nullable();

                $table
                    ->string('recovery_reason', 120)
                    ->nullable();

                $table->index(
                    [
                        'status',
                        'phase_deadline_at',
                    ],
                    'provider_sessions_phase_deadline_idx',
                );

                $table->index(
                    [
                        'cleanup_status',
                        'recovery_required_at',
                    ],
                    'provider_sessions_recovery_idx',
                );
            },
        );
    }

    /**
     * Preserve provider recovery provenance during application rollback.
     *
     * Rollback disables new code but never destructively removes execution
     * history required to investigate an interrupted Codex process.
     */
    public function down(): void
    {
        // Intentionally irreversible to prevent loss of recovery evidence.
    }
};
