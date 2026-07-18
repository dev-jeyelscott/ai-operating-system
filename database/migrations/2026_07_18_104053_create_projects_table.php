<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create organization-owned project aggregate records.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            // Table ownership: Projects module.
            $table->id();

            /*
             * Projects must always belong to an organization. Organization
             * deletion is restricted so project history is not silently lost.
             */
            $table->foreignId('organization_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            /*
             * Core aggregate metadata. Versioned stack, repository, provider,
             * policy, command, and budget configuration belongs to AIOS-021.
             */
            $table->string('name', 120);
            $table->string('slug', 180)->unique();
            $table->text('description')->nullable();
            $table->string('project_type', 32);

            // Canonical lifecycle state guarded by the domain state machine.
            $table->string('status', 64)->default('draft');
            $table->timestampTz('status_changed_at')->useCurrent();

            $table->timestamps();

            /*
             * Supports organization-scoped project lists and lifecycle filters.
             * AIOS-017 will enforce mandatory tenant filtering.
             */
            $table->index(
                ['organization_id', 'status'],
                'projects_org_status_index',
            );
        });

        /*
         * Migrations intentionally keep their own immutable vocabulary instead
         * of importing an application enum that may change in the future.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE projects
            ADD CONSTRAINT projects_project_type_check
            CHECK (
                project_type IN (
                    'web_app',
                    'api',
                    'library',
                    'service',
                    'mobile',
                    'other'
                )
            )
            SQL);

        /*
         * Reject invalid lifecycle values from raw SQL, imports, console code,
         * queue workers, or any future path that bypasses Eloquent casting.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE projects
            ADD CONSTRAINT projects_status_check
            CHECK (
                status IN (
                    'draft',
                    'configuring',
                    'documents_pending',
                    'ready_for_planning',
                    'planning',
                    'awaiting_roadmap_approval',
                    'ready_for_development',
                    'active',
                    'paused',
                    'blocked',
                    'completed',
                    'cancelled'
                )
            )
            SQL);
    }

    /**
     * Remove the projects table and its attached constraints.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
