<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add versioned, approval-set-aware snapshot identity.
     *
     * Existing snapshots are retained as schema version 1. Their fingerprint is
     * derived only from the historical fields that were available when they
     * were created.
     */
    public function up(): void
    {
        /*
         * The table's append-only trigger must be removed temporarily because
         * this migration needs to backfill identity fields on historical rows.
         */
        $this->dropMutationTrigger();

        Schema::table(
            'project_context_snapshots',
            function (Blueprint $table): void {
                $table
                    ->unsignedSmallInteger('identity_schema_version')
                    ->default(1);

                $table
                    ->char('approved_document_set_fingerprint', 64)
                    ->nullable();
            },
        );

        DB::table('project_context_snapshots')
            ->select([
                'id',
                'approved_document_versions',
            ])
            ->orderBy('id')
            ->chunkById(
                100,
                function ($snapshots): void {
                    foreach ($snapshots as $snapshot) {
                        DB::table('project_context_snapshots')
                            ->where('id', $snapshot->id)
                            ->update([
                                'identity_schema_version' => 1,
                                'approved_document_set_fingerprint' => $this->legacyFingerprint(
                                    $snapshot->approved_document_versions,
                                ),
                            ]);
                    }
                },
                'id',
            );

        DB::statement(<<<'SQL'
            ALTER TABLE project_context_snapshots
                ALTER COLUMN approved_document_set_fingerprint SET NOT NULL
            SQL);

        /*
         * Remove the old identity constraint. It incorrectly allows only one
         * snapshot for each project/configuration revision combination.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE project_context_snapshots
                DROP CONSTRAINT IF EXISTS
                    project_context_snapshots_project_id_project_configuration_version_id_unique
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE project_context_snapshots
                ADD CONSTRAINT project_context_snapshots_identity_unique
                    UNIQUE (
                        project_id,
                        project_configuration_version_id,
                        identity_schema_version,
                        approved_document_set_fingerprint
                    ),
                ADD CONSTRAINT project_context_snapshots_identity_schema_check
                    CHECK (identity_schema_version >= 1),
                ADD CONSTRAINT project_context_snapshots_fingerprint_check
                    CHECK (
                        approved_document_set_fingerprint
                        ~ '^[0-9a-f]{64}$'
                    )
            SQL);

        $this->createMutationTrigger();
    }

    /**
     * Remove the new identity contract only when no version 2 snapshots exist.
     *
     * Refusing rollback in that case prevents distinct approved-document sets
     * from collapsing back into the old lossy unique constraint.
     */
    public function down(): void
    {
        if (
            DB::table('project_context_snapshots')
                ->where('identity_schema_version', '>=', 2)
                ->exists()
        ) {
            throw new LogicException(
                'Cannot roll back snapshot identity while version 2 snapshots exist.',
            );
        }

        $this->dropMutationTrigger();

        DB::statement(<<<'SQL'
            ALTER TABLE project_context_snapshots
                DROP CONSTRAINT IF EXISTS
                    project_context_snapshots_identity_unique,
                DROP CONSTRAINT IF EXISTS
                    project_context_snapshots_identity_schema_check,
                DROP CONSTRAINT IF EXISTS
                    project_context_snapshots_fingerprint_check
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE project_context_snapshots
                ADD CONSTRAINT
                    project_context_snapshots_project_id_project_configuration_version_id_unique
                UNIQUE (
                    project_id,
                    project_configuration_version_id
                )
            SQL);

        Schema::table(
            'project_context_snapshots',
            function (Blueprint $table): void {
                $table->dropColumn([
                    'identity_schema_version',
                    'approved_document_set_fingerprint',
                ]);
            },
        );

        $this->createMutationTrigger();
    }

    /**
     * Compute a stable fingerprint for one historical version 1 snapshot.
     */
    private function legacyFingerprint(mixed $rawEntries): string
    {
        $entries = is_string($rawEntries)
            ? json_decode(
                $rawEntries,
                true,
                512,
                JSON_THROW_ON_ERROR,
            )
            : $rawEntries;

        if (! is_array($entries)) {
            throw new LogicException(
                'Historical snapshot document identities are malformed.',
            );
        }

        $canonicalEntries = [];

        foreach ($entries as $entry) {
            if (
                ! is_array($entry)
                || ! isset(
                    $entry['document_id'],
                    $entry['document_version_id'],
                    $entry['version'],
                    $entry['checksum_sha256'],
                )
            ) {
                throw new LogicException(
                    'Historical snapshot document identities are incomplete.',
                );
            }

            $canonicalEntries[] = [
                'document_id' => (int) $entry['document_id'],
                'document_version_id' => (int) $entry['document_version_id'],
                'version' => (int) $entry['version'],
                'checksum_sha256' => (string) $entry['checksum_sha256'],
            ];
        }

        usort(
            $canonicalEntries,
            static fn (array $left, array $right): int => [
                $left['document_id'],
                $left['version'],
                $left['document_version_id'],
            ] <=> [
                $right['document_id'],
                $right['version'],
                $right['document_version_id'],
            ],
        );

        return hash(
            'sha256',
            json_encode(
                [
                    'schema_version' => 1,
                    'documents' => $canonicalEntries,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ),
        );
    }

    /**
     * Temporarily disable update/delete rejection during the backfill.
     */
    private function dropMutationTrigger(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS
                project_context_snapshots_reject_update_delete
            ON project_context_snapshots
            SQL);
    }

    /**
     * Restore the database-level append-only protection.
     */
    private function createMutationTrigger(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER project_context_snapshots_reject_update_delete
            BEFORE UPDATE OR DELETE ON project_context_snapshots
            FOR EACH ROW
            EXECUTE FUNCTION
                public.aios_reject_project_context_snapshot_mutation()
            SQL);
    }
};
