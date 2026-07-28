<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('external_ticket_mappings', function (Blueprint $table): void {
            $table->string('last_provider_request_id', 255)->nullable()->after('state');
            $table->unsignedInteger('attempt_count')->default(0)->after('last_provider_request_id');
            $table->timestampTz('last_attempted_at')->nullable()->after('attempt_count');
            $table->timestampTz('last_published_at')->nullable()->after('last_attempted_at');
            $table->string('reconciliation_state', 40)->nullable()->after('last_published_at');
            $table->string('reconciliation_fingerprint', 64)->nullable()->after('reconciliation_state');
            $table->timestampTz('reconciled_at')->nullable()->after('reconciliation_fingerprint');

            $table->unique(
                ['roadmap_task_id', 'provider'],
                'external_ticket_mappings_task_provider_unique',
            );
            $table->index(
                ['provider', 'state'],
                'external_ticket_mappings_provider_state_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('external_ticket_mappings', function (Blueprint $table): void {
            $table->dropIndex('external_ticket_mappings_provider_state_index');
            $table->dropUnique('external_ticket_mappings_task_provider_unique');
            $table->dropColumn([
                'last_provider_request_id',
                'attempt_count',
                'last_attempted_at',
                'last_published_at',
                'reconciliation_state',
                'reconciliation_fingerprint',
                'reconciled_at',
            ]);
        });
    }
};
