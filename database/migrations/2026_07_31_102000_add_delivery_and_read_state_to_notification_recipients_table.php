<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add persistent in-app delivery and recipient read state.
     *
     * Existing assignments are preserved and backfilled as delivered at their
     * original creation time. No notification content is modified or deleted.
     */
    public function up(): void
    {
        Schema::table(
            'notification_recipients',
            function (Blueprint $table): void {
                /*
                 * The nullable first step avoids a table rewrite failure while
                 * existing recipient assignments are being backfilled.
                 */
                $table->timestampTz(
                    'delivered_at',
                    precision: 6,
                )->nullable();

                /*
                 * A null value represents an unread notification.
                 */
                $table->timestampTz(
                    'read_at',
                    precision: 6,
                )->nullable();
            },
        );

        /*
         * AIOS-059 recipient assignments already represented durable in-app
         * availability. Preserve that historical meaning by using created_at
         * as their initial delivery timestamp.
         */
        DB::statement(<<<'SQL'
            UPDATE notification_recipients
            SET delivered_at = created_at
            WHERE delivered_at IS NULL
            SQL);

        /*
         * Every new assignment is considered delivered when it is durably
         * inserted. This avoids changing every existing event materializer.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE notification_recipients
                ALTER COLUMN delivered_at
                    SET DEFAULT CURRENT_TIMESTAMP,
                ALTER COLUMN delivered_at
                    SET NOT NULL
            SQL);

        Schema::table(
            'notification_recipients',
            function (Blueprint $table): void {
                /*
                 * Supports recipient-scoped inbox ordering and efficient unread
                 * counts without scanning notification rows from other tenants.
                 */
                $table->index(
                    [
                        'organization_id',
                        'recipient_user_id',
                        'read_at',
                        'created_at',
                        'id',
                    ],
                    'notification_recipients_inbox_read_index',
                );
            },
        );
    }

    /**
     * Remove only the state introduced by this migration.
     *
     * Do not execute this rollback in production after users have accumulated
     * read-state history. Prefer a forward corrective migration instead.
     */
    public function down(): void
    {
        Schema::table(
            'notification_recipients',
            function (Blueprint $table): void {
                $table->dropIndex(
                    'notification_recipients_inbox_read_index',
                );

                $table->dropColumn([
                    'delivered_at',
                    'read_at',
                ]);
            },
        );
    }
};
