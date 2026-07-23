<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add administrative archive state to projects.
     *
     * Archive state is intentionally separate from the project workflow status.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->timestampTz('archived_at')->nullable();

            /*
             * Supports organization-scoped active and archived project lists.
             */
            $table->index(
                ['organization_id', 'archived_at'],
                'projects_org_archived_index',
            );
        });
    }

    /**
     * Remove administrative archive state from projects.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex('projects_org_archived_index');
            $table->dropColumn('archived_at');
        });
    }
};
