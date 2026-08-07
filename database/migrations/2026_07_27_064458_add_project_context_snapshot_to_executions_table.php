<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add immutable project-context lineage to logical executions.
     */
    public function up(): void
    {
        /*
         * PostgreSQL requires the referenced column combination to be unique
         * before the composite tenant-integrity foreign key can be created.
         */
        Schema::table(
            'project_context_snapshots',
            function (Blueprint $table): void {
                $table->unique(
                    ['id', 'project_id'],
                    'project_context_snapshots_id_project_unique',
                );
            },
        );

        Schema::table(
            'executions',
            function (Blueprint $table): void {
                $table->unsignedBigInteger(
                    'project_context_snapshot_id',
                )
                    ->nullable()
                    ->after('workflow_instance_id');

                $table->index(
                    [
                        'project_context_snapshot_id',
                        'status',
                    ],
                    'executions_context_snapshot_status_index',
                );
            },
        );

        /*
         * Prevent an execution for Project A from referencing a context
         * snapshot owned by Project B, including through raw SQL.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE executions
                ADD CONSTRAINT executions_context_snapshot_project_foreign
                    FOREIGN KEY (
                        project_context_snapshot_id,
                        project_id
                    )
                    REFERENCES project_context_snapshots (
                        id,
                        project_id
                    )
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT
            SQL);
    }

    /**
     * Preserve execution provenance once the migration has been deployed.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'Execution context-snapshot lineage is forward-only and must not be removed.',
        );
    }
};
