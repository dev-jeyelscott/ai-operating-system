<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ticket_execution_leases DROP CONSTRAINT ticket_execution_leases_valid_heartbeat_check');
        DB::statement(<<<'SQL'
            ALTER TABLE ticket_execution_leases
            ADD CONSTRAINT ticket_execution_leases_valid_heartbeat_check
            CHECK (heartbeat_at >= acquired_at AND expires_at > heartbeat_at)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE ticket_execution_leases
            ADD CONSTRAINT ticket_execution_leases_release_reason_check
            CHECK (release_reason IS NULL OR release_reason IN ('completion', 'terminal_failure', 'cancellation', 'manual_recovery'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ticket_execution_leases DROP CONSTRAINT ticket_execution_leases_release_reason_check');
        DB::statement('ALTER TABLE ticket_execution_leases DROP CONSTRAINT ticket_execution_leases_valid_heartbeat_check');
        DB::statement(<<<'SQL'
            ALTER TABLE ticket_execution_leases
            ADD CONSTRAINT ticket_execution_leases_valid_heartbeat_check
            CHECK (heartbeat_at >= acquired_at AND heartbeat_at <= expires_at)
        SQL);
    }
};
