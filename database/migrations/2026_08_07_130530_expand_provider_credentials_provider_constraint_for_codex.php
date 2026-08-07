<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Expand the provider credential constraint to permit project-scoped
     * Codex credentials while preserving the existing Notion provider.
     *
     * The replacement constraint is installed and validated before the old
     * constraint is removed so the table never exists without validation.
     */
    public function up(): void
    {
        /*
         * PostgreSQL NOT VALID avoids an immediate full-table validation scan
         * while still enforcing the new constraint for new and updated rows.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE provider_credentials
            ADD CONSTRAINT provider_credentials_provider_check_v2
            CHECK (provider IN ('notion', 'codex'))
            NOT VALID
        SQL);

        /*
         * Validate all existing credential rows before replacing the original
         * stricter constraint.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE provider_credentials
            VALIDATE CONSTRAINT provider_credentials_provider_check_v2
        SQL);

        /*
         * The new validated constraint is now authoritative, so the original
         * Notion-only constraint may safely be removed.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE provider_credentials
            DROP CONSTRAINT provider_credentials_provider_check
        SQL);

        /*
         * Preserve the established constraint name so diagnostics and future
         * migrations continue referencing one stable database identifier.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE provider_credentials
            RENAME CONSTRAINT provider_credentials_provider_check_v2
            TO provider_credentials_provider_check
        SQL);
    }

    /**
     * Restore the previous Notion-only constraint only when no Codex
     * credentials exist, preventing destructive rollback of valid data.
     */
    public function down(): void
    {
        $hasCodexCredentials = DB::table('provider_credentials')
            ->where('provider', 'codex')
            ->exists();

        if ($hasCodexCredentials) {
            throw new RuntimeException(
                'Cannot restore the Notion-only provider credential constraint while Codex credentials exist.',
            );
        }

        /*
         * Install and validate the previous constraint before removing the
         * current one so rollback never leaves the table unconstrained.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE provider_credentials
            ADD CONSTRAINT provider_credentials_provider_check_v1
            CHECK (provider IN ('notion'))
            NOT VALID
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE provider_credentials
            VALIDATE CONSTRAINT provider_credentials_provider_check_v1
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE provider_credentials
            DROP CONSTRAINT provider_credentials_provider_check
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE provider_credentials
            RENAME CONSTRAINT provider_credentials_provider_check_v1
            TO provider_credentials_provider_check
        SQL);
    }
};
