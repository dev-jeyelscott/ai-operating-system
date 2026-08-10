<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Add the provider-session lineage required by Codex approval requests. */
    public function up(): void
    {
        Schema::table(
            'codex_approval_requests',
            function (Blueprint $table): void {
                $table
                    ->foreignUlid('provider_session_id')
                    ->constrained('provider_sessions')
                    ->restrictOnDelete();
            },
        );
    }

    /** Remove the provider-session lineage during rollback. */
    public function down(): void
    {
        Schema::table(
            'codex_approval_requests',
            function (Blueprint $table): void {
                $table->dropConstrainedForeignId('provider_session_id');
            },
        );
    }
};
