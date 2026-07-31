<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the rebuildable office projection table without changing source data.
     */
    public function up(): void
    {
        Schema::create('office_projections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('project_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->unsignedBigInteger('last_event_sequence')->default(0);
            $table->string('last_event_id', 26)->nullable();
            $table->char('fingerprint', 64);
            $table->jsonb('state');
            $table->timestampTz('projected_at');
            $table->timestampTz('rebuilt_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['organization_id', 'project_id'],
                'office_projections_organization_project_unique',
            );
            $table->index(
                ['organization_id', 'updated_at'],
                'office_projections_organization_updated_index',
            );
            $table->index(
                ['project_id', 'last_event_sequence'],
                'office_projections_project_checkpoint_index',
            );
        });
    }

    /**
     * Remove only the rebuildable projection table during rollback.
     */
    public function down(): void
    {
        Schema::dropIfExists('office_projections');
    }
};
