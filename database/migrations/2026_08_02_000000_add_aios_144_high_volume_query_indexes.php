<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Concurrent PostgreSQL index creation cannot run inside a transaction.
     */
    public $withinTransaction = false;

    /**
     * Indexes supporting the four high-volume AIOS-144 query paths.
     *
     * These definitions add no columns and modify no application data.
     *
     * @var array<string, string>
     */
    private const array INDEX_DEFINITIONS = [
        'roadmaps_approved_project_revision_idx' => <<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS "roadmaps_approved_project_revision_idx"
            ON "roadmaps" ("project_id", "revision" DESC)
            INCLUDE (
                "id",
                "project_context_snapshot_id",
                "approved_at"
            )
            WHERE "status" = 'approved'
              AND "approved_at" IS NOT NULL
            SQL,

        'roadmap_tasks_workable_candidates_idx' => <<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS "roadmap_tasks_workable_candidates_idx"
            ON "roadmap_tasks" (
                "roadmap_id",
                "position",
                "stable_id"
            )
            WHERE "status" IN ('ready', 'changes_requested')
            SQL,

        'roadmap_tasks_projection_order_idx' => <<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS "roadmap_tasks_projection_order_idx"
            ON "roadmap_tasks" (
                "roadmap_id",
                "position",
                "stable_id"
            )
            SQL,

        'ticket_execution_leases_project_active_idx' => <<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS "ticket_execution_leases_project_active_idx"
            ON "ticket_execution_leases" (
                "project_id",
                "acquired_at",
                "id"
            )
            INCLUDE (
                "roadmap_task_id",
                "execution_id"
            )
            WHERE "released_at" IS NULL
            SQL,

        'audit_events_organization_sequence_idx' => <<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS "audit_events_organization_sequence_idx"
            ON "audit_events" (
                "organization_id",
                "sequence" DESC
            )
            INCLUDE ("occurred_at")
            SQL,

        'audit_events_project_sequence_idx' => <<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS "audit_events_project_sequence_idx"
            ON "audit_events" (
                "organization_id",
                "project_id",
                "sequence" DESC
            )
            INCLUDE ("occurred_at")
            WHERE "project_id" IS NOT NULL
            SQL,

        'notification_recipients_inbox_idx' => <<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS "notification_recipients_inbox_idx"
            ON "notification_recipients" (
                "organization_id",
                "recipient_user_id",
                "created_at" DESC,
                "id" DESC
            )
            INCLUDE (
                "notification_event_id",
                "delivered_at",
                "read_at"
            )
            SQL,

        'notification_recipients_unread_idx' => <<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS "notification_recipients_unread_idx"
            ON "notification_recipients" (
                "organization_id",
                "recipient_user_id"
            )
            WHERE "read_at" IS NULL
            SQL,

        'outbox_messages_project_sequence_idx' => <<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS "outbox_messages_project_sequence_idx"
            ON "outbox_messages" (
                "organization_id",
                "project_id",
                "sequence" DESC
            )
            INCLUDE ("event_id")
            WHERE "project_id" IS NOT NULL
            SQL,

        'executions_project_created_idx' => <<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS "executions_project_created_idx"
            ON "executions" (
                "project_id",
                "created_at" DESC,
                "id"
            )
            INCLUDE ("status")
            SQL,

        'execution_attempts_latest_idx' => <<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS "execution_attempts_latest_idx"
            ON "execution_attempts" (
                "execution_id",
                "attempt_number" DESC
            )
            SQL,

        'approvals_project_pending_idx' => <<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS "approvals_project_pending_idx"
            ON "approvals" (
                "project_id",
                "requested_at",
                "id"
            )
            WHERE "status" = 'pending'
            SQL,
    ];

    /**
     * Create the measured high-volume query indexes without blocking writes.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SET lock_timeout TO '5s'");

        try {
            foreach (self::INDEX_DEFINITIONS as $indexName => $statement) {
                /*
                 * A failed concurrent build may leave an invalid index behind.
                 * Remove only an invalid same-name index before retrying.
                 */
                $this->dropInvalidIndex($indexName);

                DB::statement($statement);
            }
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    /**
     * Remove only the indexes introduced by this migration.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SET lock_timeout TO '5s'");

        try {
            $indexNames = array_reverse(
                array_keys(self::INDEX_DEFINITIONS),
            );

            foreach ($indexNames as $indexName) {
                DB::statement(sprintf(
                    'DROP INDEX CONCURRENTLY IF EXISTS %s',
                    $this->quoteIdentifier($indexName),
                ));
            }
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    /**
     * Drop a same-name index only when PostgreSQL marks it invalid.
     */
    private function dropInvalidIndex(string $indexName): void
    {
        $result = DB::selectOne(
            <<<'SQL'
                SELECT
                    NOT index_definition.indisvalid AS is_invalid
                FROM pg_class AS index_relation
                JOIN pg_index AS index_definition
                  ON index_definition.indexrelid = index_relation.oid
                JOIN pg_namespace AS namespace
                  ON namespace.oid = index_relation.relnamespace
                WHERE namespace.nspname = current_schema()
                  AND index_relation.relname = ?
                SQL,
            [$indexName],
        );

        if ($result === null) {
            return;
        }

        $attributes = (array) $result;

        if (! $this->postgresBoolean(
            $attributes['is_invalid'] ?? false,
        )) {
            return;
        }

        DB::statement(sprintf(
            'DROP INDEX CONCURRENTLY IF EXISTS %s',
            $this->quoteIdentifier($indexName),
        ));
    }

    /**
     * Convert a PostgreSQL boolean result to a strict PHP boolean.
     */
    private function postgresBoolean(mixed $value): bool
    {
        return in_array(
            $value,
            [true, 1, '1', 't', 'true'],
            true,
        );
    }

    /**
     * Safely quote a known PostgreSQL identifier.
     */
    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace(
            '"',
            '""',
            $identifier,
        ).'"';
    }
};
