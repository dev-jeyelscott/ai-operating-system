<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE project_integrations DROP CONSTRAINT project_integrations_failure_code_check');
        DB::statement("ALTER TABLE project_integrations ADD CONSTRAINT project_integrations_failure_code_check CHECK (last_failure_code IS NULL OR last_failure_code IN ('invalid_token', 'missing_read_capability', 'database_not_shared', 'workspace_mismatch', 'rate_limited', 'provider_unavailable', 'invalid_provider_response', 'schema_incompatible', 'connection_failed'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE project_integrations DROP CONSTRAINT project_integrations_failure_code_check');
        DB::statement("ALTER TABLE project_integrations ADD CONSTRAINT project_integrations_failure_code_check CHECK (last_failure_code IS NULL OR last_failure_code IN ('invalid_token', 'missing_read_capability', 'database_not_shared', 'workspace_mismatch', 'rate_limited', 'provider_unavailable', 'invalid_provider_response', 'connection_failed'))");
    }
};
