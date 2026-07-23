<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create explicit user-to-organization membership records.
     */
    public function up(): void
    {
        Schema::create(
            'organization_memberships',
            function (Blueprint $table): void {
                // Table ownership: Identity module.
                $table->id();

                // Removing an organization removes all of its memberships.
                $table->foreignId('organization_id')
                    ->constrained()
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();

                // User deletion must first resolve organization memberships.
                // This prevents an owner from being deleted accidentally.
                $table->foreignId('user_id')
                    ->constrained()
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();

                // Persist the backed enum value.
                $table->string('role', 32);

                $table->timestamps();

                // A user may have only one membership per organization.
                $table->unique(
                    ['organization_id', 'user_id'],
                    'organization_memberships_org_user_unique',
                );

                // Supports loading all organizations for one user.
                $table->index(
                    ['user_id', 'organization_id'],
                    'organization_memberships_user_org_index',
                );
            },
        );

        // Protect the role vocabulary even when records are written outside
        // Eloquent, such as imports, maintenance scripts, or future workers.
        DB::statement(<<<'SQL'
            ALTER TABLE organization_memberships
            ADD CONSTRAINT organization_memberships_role_check
            CHECK (
                role IN (
                    'owner',
                    'administrator',
                    'member',
                    'viewer'
                )
            )
            SQL);
    }

    /**
     * Remove the membership table and its attached constraints.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_memberships');
    }
};
