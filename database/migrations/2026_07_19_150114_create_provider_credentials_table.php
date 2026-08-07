<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create encrypted project-scoped provider credential storage.
     */
    public function up(): void
    {
        /*
         * PostgreSQL requires the referenced column combination to be unique
         * before it can be used by the composite tenant-integrity foreign key.
         */
        Schema::table('projects', function (Blueprint $table): void {
            $table->unique(
                ['id', 'organization_id'],
                'projects_id_organization_unique',
            );
        });

        Schema::create(
            'provider_credentials',
            function (Blueprint $table): void {
                // Table ownership: Integrations module.
                $table->id();

                /*
                 * organization_id is repeated deliberately so every credential
                 * query can require an explicit tenant identifier.
                 */
                $table->foreignId('organization_id')
                    ->constrained()
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();

                $table->unsignedBigInteger('project_id');

                $table->string('provider', 32);

                /*
                 * Ciphertext length is not deterministic and is larger than the
                 * original plaintext, so this must be TEXT rather than VARCHAR.
                 */
                $table->text('secret_ciphertext');

                /*
                 * Incremented only when a materially different external
                 * credential replaces the existing value.
                 */
                $table->unsignedBigInteger('version')->default(1);

                /*
                 * Actor references are nullable so account deletion cannot
                 * delete or invalidate the credential record.
                 */
                $table->foreignId('created_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->foreignId('last_rotated_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->timestampTz('rotated_at')->nullable();
                $table->timestampsTz();

                /*
                 * One active credential is stored for each project/provider
                 * pair. A replacement is a rotation, not a second active row.
                 */
                $table->unique(
                    ['project_id', 'provider'],
                    'provider_credentials_project_provider_unique',
                );

                $table->index(
                    ['organization_id', 'project_id', 'provider'],
                    'provider_credentials_tenant_lookup_index',
                );

                /*
                 * Prevent a project identifier from being paired with another
                 * organization's identifier, even through direct SQL.
                 */
                $table->foreign(
                    ['project_id', 'organization_id'],
                    'provider_credentials_project_tenant_foreign',
                )
                    ->references(['id', 'organization_id'])
                    ->on('projects')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();
            },
        );

        /*
         * Database checks protect writes that bypass the application layer.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE provider_credentials
                ADD CONSTRAINT provider_credentials_provider_check
                    CHECK (provider IN ('notion')),
                ADD CONSTRAINT provider_credentials_ciphertext_check
                    CHECK (octet_length(secret_ciphertext) > 0),
                ADD CONSTRAINT provider_credentials_version_check
                    CHECK (version >= 1)
            SQL);
    }

    /**
     * Remove encrypted credentials and the supporting composite project key.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_credentials');

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropUnique('projects_id_organization_unique');
        });
    }
};
