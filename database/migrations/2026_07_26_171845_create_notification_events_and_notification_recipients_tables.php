<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create normalized notification events and tenant-safe recipients.
     */
    public function up(): void
    {
        /*
         * PostgreSQL requires a unique key matching the complete referenced
         * column set before a composite foreign key can enforce that a project
         * and notification event belong to the same organization.
         *
         * Adding this unique constraint is non-destructive because project IDs
         * are already primary keys.
         */
        Schema::create(
            'notification_events',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();

                /*
                 * Every notification belongs to exactly one organization.
                 * Organization deletion remains restricted so notification
                 * history cannot disappear through an accidental cascade.
                 */
                $table->foreignId('organization_id')
                    ->constrained()
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();

                /*
                 * Some notifications are organization-wide and therefore do
                 * not belong to an individual project.
                 */
                $table->foreignId('project_id')->nullable();

                /*
                 * source_event_id is the originating DomainEventEnvelope event
                 * ID. Its unique constraint makes materialization idempotent
                 * under domain-event replay.
                 */
                $table->ulid('source_event_id');

                $table->string('event_name', 191);

                /*
                 * Store only sanitized, display-safe notification content.
                 * Raw prompts, credentials, provider responses, and unredacted
                 * domain-event payloads must not be persisted here.
                 */
                $table->string('title', 191);
                $table->text('message');
                $table->text('action_url')->nullable();
                $table->jsonb('data');

                /*
                 * Preserve trace identifiers required for execution inspection,
                 * audit correlation, and operational troubleshooting.
                 */
                $table->ulid('correlation_id');
                $table->ulid('execution_id')->nullable();

                $table->timestampTz('occurred_at', precision: 6);
                $table->timestampTz('created_at', precision: 6)
                    ->useCurrent();

                $table->unique(
                    'source_event_id',
                    'notification_events_source_event_unique',
                );

                /*
                 * This key supports the recipient composite foreign key below.
                 */
                $table->unique(
                    ['id', 'organization_id'],
                    'notification_events_id_organization_unique',
                );

                /*
                 * Enforce that project-scoped notifications reference a project
                 * owned by the same organization.
                 *
                 * PostgreSQL's default MATCH SIMPLE behavior permits project_id
                 * to be null for organization-wide notifications.
                 */
                $table->foreign(
                    ['project_id', 'organization_id'],
                    'notification_events_project_organization_foreign',
                )
                    ->references(['id', 'organization_id'])
                    ->on('projects')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();

                $table->index(
                    ['organization_id', 'project_id', 'occurred_at'],
                    'notification_events_scope_occurred_index',
                );

                $table->index(
                    ['event_name', 'occurred_at'],
                    'notification_events_name_occurred_index',
                );

                $table->index(
                    ['execution_id', 'occurred_at'],
                    'notification_events_execution_occurred_index',
                );

                $table->index(
                    'correlation_id',
                    'notification_events_correlation_index',
                );
            },
        );

        /*
         * Migrations own their persistence vocabulary. Do not import mutable
         * application enums into database constraints.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE notification_events
                ADD CONSTRAINT notification_events_name_check
                    CHECK (
                        event_name
                            ~ '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$'
                    ),
                ADD CONSTRAINT notification_events_title_check
                    CHECK (btrim(title) <> ''),
                ADD CONSTRAINT notification_events_message_check
                    CHECK (btrim(message) <> ''),
                ADD CONSTRAINT notification_events_action_url_check
                    CHECK (
                        action_url IS NULL
                        OR btrim(action_url) <> ''
                    ),
                ADD CONSTRAINT notification_events_data_shape_check
                    CHECK (jsonb_typeof(data) = 'object')
            SQL);

        Schema::create(
            'notification_recipients',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();

                $table->ulid('notification_event_id');
                $table->unsignedBigInteger('organization_id');

                /*
                 * MVP notification recipients are authenticated AIOS users.
                 * External email addresses, Slack users, and channel routing do
                 * not belong to AIOS-059.
                 */
                $table->foreignId('recipient_user_id')
                    ->constrained('users')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();

                /*
                 * AIOS-115 may add delivery and read timestamps. AIOS-059 stores
                 * only assignment time and the deduplication identity.
                 */
                $table->timestampTz('created_at', precision: 6)
                    ->useCurrent();

                /*
                 * This is the final concurrency guard. Event replay or competing
                 * consumers cannot assign the same event to the same user twice.
                 */
                $table->unique(
                    ['notification_event_id', 'recipient_user_id'],
                    'notification_recipients_event_user_unique',
                );

                /*
                 * Ensure the recipient row uses the same organization as its
                 * parent notification event.
                 */
                $table->foreign(
                    ['notification_event_id', 'organization_id'],
                    'notification_recipients_event_organization_foreign',
                )
                    ->references(['id', 'organization_id'])
                    ->on('notification_events')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();

                /*
                 * Supports the future in-app inbox query without requiring a
                 * full table scan.
                 */
                $table->index(
                    ['organization_id', 'recipient_user_id', 'created_at'],
                    'notification_recipients_scope_user_created_index',
                );
            },
        );

        /*
         * Validate membership only when a recipient is assigned or its tenant
         * identity changes.
         *
         * This deliberately uses a trigger instead of a permanent foreign key
         * to organization_memberships. Historical recipient rows therefore do
         * not prevent a membership from being removed later.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION
                public.aios_validate_notification_recipient_membership()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM organization_memberships
                    WHERE organization_id = NEW.organization_id
                      AND user_id = NEW.recipient_user_id
                ) THEN
                    RAISE EXCEPTION USING
                        MESSAGE =
                            'notification recipient must belong to the event organization',
                        ERRCODE = '23503';
                END IF;

                RETURN NEW;
            END;
            $$;
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER notification_recipients_validate_membership
            BEFORE INSERT OR UPDATE OF organization_id, recipient_user_id
                ON notification_recipients
            FOR EACH ROW
            EXECUTE FUNCTION
                aios_validate_notification_recipient_membership()
            SQL);
    }

    /**
     * Remove notification persistence during an explicitly approved rollback.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
        DROP TRIGGER IF EXISTS
            notification_recipients_validate_membership
            ON notification_recipients
        SQL);

        DB::unprepared(<<<'SQL'
        DROP FUNCTION IF EXISTS
            public.aios_validate_notification_recipient_membership()
        SQL);

        Schema::dropIfExists('notification_recipients');
        Schema::dropIfExists('notification_events');
    }
};
