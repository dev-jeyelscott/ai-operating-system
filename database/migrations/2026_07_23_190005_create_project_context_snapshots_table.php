<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create immutable project context snapshots.
     */
    public function up(): void
    {
        Schema::create(
            'project_context_snapshots',
            function (Blueprint $table): void {
                $table->id();

                $table
                    ->foreignId('project_id')
                    ->constrained()
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();

                $table
                    ->foreignId('project_configuration_version_id')
                    ->constrained()
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();

                $table->unsignedBigInteger('configuration_revision');
                $table->unsignedSmallInteger('identity_schema_version');

                $table->char(
                    'approved_document_set_fingerprint',
                    64,
                );

                $table->jsonb('approved_document_versions');
                $table->timestampTz('created_at')->useCurrent();

                $table->index([
                    'project_id',
                    'created_at',
                ]);

                $table->unique(
                    [
                        'project_id',
                        'project_configuration_version_id',
                        'identity_schema_version',
                        'approved_document_set_fingerprint',
                    ],
                    'project_context_snapshots_identity_unique',
                );
            },
        );

        DB::statement(<<<'SQL'
            ALTER TABLE project_context_snapshots
                ADD CONSTRAINT project_context_snapshots_document_versions_shape_check
                    CHECK (
                        jsonb_typeof(approved_document_versions) = 'array'
                    ),
                ADD CONSTRAINT project_context_snapshots_identity_schema_check
                    CHECK (
                        identity_schema_version >= 1
                    ),
                ADD CONSTRAINT project_context_snapshots_fingerprint_check
                    CHECK (
                        approved_document_set_fingerprint
                        ~ '^[0-9a-f]{64}$'
                    )
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.aios_reject_project_context_snapshot_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION USING
                    MESSAGE = 'project_context_snapshots is append-only',
                    ERRCODE = '55000';
            END;
            $$
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER project_context_snapshots_reject_update_delete
            BEFORE UPDATE OR DELETE ON project_context_snapshots
            FOR EACH ROW
            EXECUTE FUNCTION
                public.aios_reject_project_context_snapshot_mutation()
            SQL);
    }

    /**
     * Remove the immutable snapshot storage.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_context_snapshots');

        DB::unprepared(
            'DROP FUNCTION IF EXISTS public.aios_reject_project_context_snapshot_mutation()',
        );
    }
};
