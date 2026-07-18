<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the append-only application audit-event store.
     */
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            // Table ownership: Audit module.
            $table->bigIncrements('sequence');

            /*
             * Public event identity. Sequence is used for deterministic ordering,
             * while event_id is safe to expose in future audit interfaces.
             */
            $table->ulid('event_id')->unique();

            /*
             * Historical identifiers intentionally do not use foreign keys.
             * Audit history must survive ordinary aggregate deletion and must
             * never cascade-update or cascade-delete with another module.
             */
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id')->nullable();

            $table->string('actor_type', 32);
            $table->string('actor_id', 191);

            $table->string('event_type', 120);

            $table->string('subject_type', 64);
            $table->string('subject_id', 191);

            $table->string('correlation_id', 128)->nullable();

            /*
             * Metadata contains only explicitly allowlisted, redacted,
             * JSON-serializable values supplied by application services.
             */
            $table->jsonb('metadata');

            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(
                ['organization_id', 'sequence'],
                'audit_events_org_sequence_index',
            );

            $table->index(
                ['project_id', 'sequence'],
                'audit_events_project_sequence_index',
            );

            $table->index(
                'correlation_id',
                'audit_events_correlation_index',
            );

            $table->index(
                'event_type',
                'audit_events_event_type_index',
            );

            $table->index(
                'occurred_at',
                'audit_events_occurred_at_index',
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE audit_events
            ADD CONSTRAINT audit_events_actor_type_check
            CHECK (
                actor_type IN (
                    'user',
                    'system',
                    'agent',
                    'provider'
                )
            )
            SQL);

        /*
         * Reject every direct or ORM-driven update/delete operation.
         * PostgreSQL trigger failures occur inside the caller's transaction,
         * causing the prohibited mutation to roll back.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.aios_reject_audit_event_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION USING
                    MESSAGE = 'audit_events is append-only',
                    ERRCODE = '55000';
            END;
            $$;
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_events_reject_update_delete
            BEFORE UPDATE OR DELETE ON audit_events
            FOR EACH ROW
            EXECUTE FUNCTION aios_reject_audit_event_mutation()
            SQL);
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        // Dropping the table also removes the trigger that depends on the
        // append-only enforcement function.
        Schema::dropIfExists('audit_events');

        // Remove the PostgreSQL function after all dependent triggers are gone.
        DB::unprepared(
            'DROP FUNCTION IF EXISTS public.aios_reject_audit_event_mutation();',
        );
    }
};
