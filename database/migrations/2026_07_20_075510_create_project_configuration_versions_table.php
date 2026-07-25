<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the immutable project-configuration history store.
     */
    public function up(): void
    {
        Schema::create(
            'project_configuration_versions',
            function (Blueprint $table): void {
                // Table ownership: Projects module.
                $table->id();

                /*
                 * Configuration history belongs to a project. Hard deletion of
                 * a project is blocked while historical versions exist.
                 *
                 * Projects are archived during normal application operation.
                 */
                $table->foreignId('project_id')
                    ->constrained()
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();

                /*
                 * schema_version identifies the snapshot contract shape.
                 *
                 * revision is the project-scoped version number already maintained
                 * by the current project_configurations aggregate.
                 */
                $table->unsignedSmallInteger('schema_version');
                $table->unsignedBigInteger('revision');

                /*
                 * Historical actor identifiers are strings so future system,
                 * agent, and provider actors can use the same representation.
                 */
                $table->string('actor_type', 32);
                $table->string('actor_id', 191);

                /*
                 * Store a stable machine-readable reason rather than arbitrary
                 * request bodies or potentially sensitive user input.
                 */
                $table->string('change_reason', 191);

                /*
                 * Store the complete credential-free canonical configuration
                 * produced by ProjectConfiguration::toVersionedArray().
                 */
                $table->jsonb('snapshot');

                /*
                 * Version rows are append-only and therefore have no updated_at.
                 */
                $table->timestampTz('created_at')->useCurrent();

                /*
                 * A revision may be recorded only once for a project.
                 */
                $table->unique(
                    ['project_id', 'revision'],
                    'project_configuration_versions_project_revision_unique',
                );

                $table->index(
                    ['project_id', 'created_at'],
                    'project_configuration_versions_project_created_index',
                );
            },
        );

        /*
         * Protect invariant values even when a write bypasses Eloquent.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE project_configuration_versions
                ADD CONSTRAINT project_configuration_versions_schema_version_check
                    CHECK (schema_version >= 1),
                ADD CONSTRAINT project_configuration_versions_revision_check
                    CHECK (revision >= 1),
                ADD CONSTRAINT project_configuration_versions_actor_type_check
                    CHECK (
                        actor_type IN (
                            'user',
                            'system',
                            'agent',
                            'provider'
                        )
                    ),
                ADD CONSTRAINT project_configuration_versions_actor_id_check
                    CHECK (
                        char_length(btrim(actor_id))
                        BETWEEN 1 AND 191
                    ),
                ADD CONSTRAINT project_configuration_versions_reason_check
                    CHECK (
                        char_length(btrim(change_reason))
                        BETWEEN 1 AND 191
                    ),
                ADD CONSTRAINT project_configuration_versions_snapshot_shape_check
                    CHECK (
                        jsonb_typeof(snapshot) = 'object'
                    )
            SQL);

        /*
         * Reject every direct or ORM-driven UPDATE and DELETE.
         *
         * This follows the same append-only enforcement strategy currently used
         * by audit_events.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION
                public.aios_reject_project_configuration_version_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION USING
                    MESSAGE = 'project_configuration_versions is append-only',
                    ERRCODE = '55000';
            END;
            $$;
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER
                project_configuration_versions_reject_update_delete
            BEFORE UPDATE OR DELETE
                ON project_configuration_versions
            FOR EACH ROW
            EXECUTE FUNCTION
                aios_reject_project_configuration_version_mutation()
            SQL);
    }

    /**
     * Remove the history table and append-only trigger function.
     */
    public function down(): void
    {
        /*
         * Dropping the table also removes its trigger.
         */
        Schema::dropIfExists('project_configuration_versions');

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS
                public.aios_reject_project_configuration_version_mutation()
            SQL);
    }
};
