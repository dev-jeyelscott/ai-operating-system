<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen notification trace identifiers to match the authoritative audit
     * trace-ID contract.
     *
     * Audit correlation and execution identifiers may contain up to 128
     * characters. Notification events copy those identifiers from their source
     * audit event, so storing them as fixed 26-character ULIDs is too narrow.
     *
     * The source_event_id column intentionally remains ULID-sized because
     * authoritative audit event IDs themselves are generated as ULIDs.
     */
    public function up(): void
    {
        Schema::table('notification_events', function (Blueprint $table): void {
            $table->string('correlation_id', 128)->change();

            $table->string('execution_id', 128)
                ->nullable()
                ->change();
        });
    }

    /**
     * Prevent a destructive rollback that could truncate valid trace IDs.
     *
     * Widening these columns is backward compatible with the previous
     * application contract, so operational rollback should leave the wider
     * storage in place rather than risk losing correlation information.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'Notification trace identifiers cannot be safely reduced to 26 characters.',
        );
    }
};
