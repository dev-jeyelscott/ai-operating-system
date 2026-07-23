<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create safe provider-specific project integration metadata.
     */
    public function up(): void
    {
        Schema::create(
            'project_integrations',
            function (Blueprint $table): void {
                $table->id();

                /*
                 * organization_id is intentionally duplicated so every query can
                 * require explicit tenant context.
                 */
                $table->foreignId('organization_id')
                    ->constrained()
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();

                $table->unsignedBigInteger('project_id');

                $table->string('provider', 32);

                /*
                 * These are provider resource identifiers, not credentials.
                 */
                $table->uuid('workspace_id')->nullable();
                $table->string('workspace_name', 255)->nullable();

                $table->uuid('database_id')->nullable();
                $table->string('database_name', 255)->nullable();
                $table->uuid('data_source_id')->nullable();
                $table->string('data_source_name', 255)->nullable();

                $table->string('connection_status', 32);
                $table->string('last_failure_code', 64)->nullable();

                /*
                 * Store only the provider request ID needed for support and
                 * correlation. Never persist authorization headers or payloads.
                 */
                $table->string(
                    'last_provider_request_id',
                    255,
                )->nullable();

                $table->foreignId('last_tested_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->timestampTz('last_tested_at');
                $table->timestampTz('last_connected_at')->nullable();

                $table->timestampsTz();

                $table->unique(
                    ['project_id', 'provider'],
                    'project_integrations_project_provider_unique',
                );

                $table->index(
                    [
                        'organization_id',
                        'project_id',
                        'provider',
                    ],
                    'project_integrations_tenant_lookup_index',
                );

                /*
                 * Prevent a project ID from being paired with another tenant.
                 */
                $table->foreign(
                    ['project_id', 'organization_id'],
                    'project_integrations_project_tenant_foreign',
                )
                    ->references(['id', 'organization_id'])
                    ->on('projects')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();
            },
        );

        DB::statement(<<<'SQL'
            ALTER TABLE project_integrations
                ADD CONSTRAINT project_integrations_provider_check
                    CHECK (provider IN ('notion')),

                ADD CONSTRAINT project_integrations_status_check
                    CHECK (
                        connection_status IN ('connected', 'failed')
                    ),

                ADD CONSTRAINT project_integrations_failure_code_check
                    CHECK (
                        last_failure_code IS NULL
                        OR last_failure_code IN (
                            'invalid_token',
                            'missing_read_capability',
                            'database_not_shared',
                            'workspace_mismatch',
                            'rate_limited',
                            'provider_unavailable',
                            'invalid_provider_response',
                            'connection_failed'
                        )
                    ),

                ADD CONSTRAINT project_integrations_state_check
                    CHECK (
                        (
                            connection_status = 'connected'
                            AND workspace_id IS NOT NULL
                            AND database_id IS NOT NULL
                            AND data_source_id IS NOT NULL
                            AND last_failure_code IS NULL
                        )
                        OR
                        (
                            connection_status = 'failed'
                            AND last_failure_code IS NOT NULL
                        )
                    )
            SQL);
    }

    /**
     * Remove project integration metadata.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_integrations');
    }
};
