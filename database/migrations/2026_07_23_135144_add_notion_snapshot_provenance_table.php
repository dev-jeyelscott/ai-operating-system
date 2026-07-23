<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add immutable-snapshot provenance for successfully verified targets.
     */
    public function up(): void
    {
        Schema::table(
            'project_integrations',
            function (Blueprint $table): void {
                /*
                 * Stores only the numeric version of the credential that
                 * successfully verified the current target.
                 *
                 * No plaintext or ciphertext credential material is stored here.
                 */
                $table->unsignedBigInteger('verified_credential_version')
                    ->nullable()
                    ->after('connection_status');
            },
        );

        DB::statement(<<<'SQL'
            ALTER TABLE project_integrations
                ADD CONSTRAINT project_integrations_verified_credential_version_check
                    CHECK (
                        verified_credential_version IS NULL
                        OR verified_credential_version >= 1
                    )
            SQL);

        /*
         * Snapshot schema version 2 introduces canonical integration target
         * metadata and credential-version provenance.
         *
         * Only the mutable current configurations are upgraded. Existing
         * immutable project_configuration_versions rows remain untouched.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE project_configurations
                ALTER COLUMN schema_version SET DEFAULT 2
            SQL);

        DB::table('project_configurations')->update([
            'schema_version' => 2,
        ]);
    }

    /**
     * Remove the provenance field and restore the previous mutable schema.
     */
    public function down(): void
    {
        DB::table('project_configurations')->update([
            'schema_version' => 1,
        ]);

        DB::statement(<<<'SQL'
            ALTER TABLE project_configurations
                ALTER COLUMN schema_version SET DEFAULT 1
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE project_integrations
                DROP CONSTRAINT IF EXISTS project_integrations_verified_credential_version_check
            SQL);

        Schema::table(
            'project_integrations',
            function (Blueprint $table): void {
                $table->dropColumn('verified_credential_version');
            },
        );
    }
};
