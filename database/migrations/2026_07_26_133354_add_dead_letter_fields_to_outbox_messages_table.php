<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add explicit terminal-delivery and replay metadata without modifying
     * existing event envelopes or removing historical retry information.
     */
    public function up(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table): void {
            $table
                ->timestampTz('dead_lettered_at')
                ->nullable()
                ->index();

            $table
                ->unsignedInteger('replay_count')
                ->default(0);

            $table
                ->timestampTz('last_replayed_at')
                ->nullable();
        });
    }

    /**
     * Preserve operational recovery evidence during application rollback.
     *
     * This migration is intentionally forward-only. Removing these columns
     * would destroy dead-letter and replay history. A later approved data
     * retirement migration may remove them after evidence has been archived.
     */
    public function down(): void
    {
        //
    }
};
