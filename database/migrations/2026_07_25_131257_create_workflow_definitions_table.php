<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create immutable versioned workflow definitions.
     */
    public function up(): void
    {
        Schema::create(
            'workflow_definitions',
            function (Blueprint $table): void {
                // Table ownership: Workflows module.
                $table->id();

                /*
                 * definition_key is stable across versions.
                 * version identifies one immutable business contract revision.
                 * schema_version identifies the JSON document shape.
                 */
                $table->string('definition_key', 100);
                $table->unsignedBigInteger('version');
                $table->unsignedSmallInteger('schema_version');

                $table->string('name', 191);
                $table->text('description')->nullable();

                /*
                 * Store only declarative state and transition metadata.
                 * Never store executable PHP, provider prompts, or callbacks here.
                 */
                $table->jsonb('definition');
                $table->char('checksum_sha256', 64);

                /*
                 * Definition versions are append-only and have no updated_at.
                 */
                $table->timestampTz('created_at')->useCurrent();

                $table->unique(
                    ['definition_key', 'version'],
                    'workflow_definitions_key_version_unique',
                );
            },
        );

        DB::statement(<<<'SQL'
            ALTER TABLE workflow_definitions
                ADD CONSTRAINT workflow_definitions_key_check
                    CHECK (
                        definition_key
                        ~ '^[a-z][a-z0-9_.-]{2,99}$'
                    ),
                ADD CONSTRAINT workflow_definitions_version_check
                    CHECK (version >= 1),
                ADD CONSTRAINT workflow_definitions_schema_version_check
                    CHECK (schema_version >= 1),
                ADD CONSTRAINT workflow_definitions_name_check
                    CHECK (
                        char_length(btrim(name))
                        BETWEEN 1 AND 191
                    ),
                ADD CONSTRAINT workflow_definitions_description_check
                    CHECK (
                        description IS NULL
                        OR char_length(btrim(description))
                            BETWEEN 1 AND 2000
                    ),
                ADD CONSTRAINT workflow_definitions_checksum_check
                    CHECK (
                        checksum_sha256 ~ '^[0-9a-f]{64}$'
                    ),
                ADD CONSTRAINT workflow_definitions_shape_check
                    CHECK (
                        jsonb_typeof(definition) = 'object'
                        AND COALESCE(
                            jsonb_typeof(
                                definition -> 'initial_state'
                            ) = 'string',
                            false
                        )
                        AND COALESCE(
                            jsonb_typeof(
                                definition -> 'states'
                            ) = 'array',
                            false
                        )
                        AND COALESCE(
                            jsonb_typeof(
                                definition -> 'terminal_states'
                            ) = 'array',
                            false
                        )
                        AND COALESCE(
                            jsonb_typeof(
                                definition -> 'transitions'
                            ) = 'array',
                            false
                        )
                    )
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION
                public.aios_reject_workflow_definition_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION USING
                    MESSAGE = 'workflow_definitions is append-only',
                    ERRCODE = '55000';
            END;
            $$;
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER workflow_definitions_reject_update_delete
            BEFORE UPDATE OR DELETE ON workflow_definitions
            FOR EACH ROW
            EXECUTE FUNCTION
                public.aios_reject_workflow_definition_mutation()
            SQL);
    }

    /**
     * Remove workflow-definition version storage.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_definitions');

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS
                public.aios_reject_workflow_definition_mutation()
            SQL);
    }
};
