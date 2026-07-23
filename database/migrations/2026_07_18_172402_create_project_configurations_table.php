<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the current versioned configuration aggregate for each project.
     */
    public function up(): void
    {
        Schema::create(
            'project_configurations',
            function (Blueprint $table): void {
                // Table ownership: Projects module.
                $table->id();

                /*
                 * Configuration is part of the project aggregate. Deleting a
                 * project removes its current configuration.
                 */
                $table->foreignId('project_id')
                    ->constrained()
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();

                /*
                 * schema_version describes the serialized contract shape.
                 *
                 * revision identifies material value changes within the shape
                 * and becomes the sequence used by AIOS-031 history.
                 */
                $table->unsignedSmallInteger('schema_version')->default(1);
                $table->unsignedBigInteger('revision')->default(1);

                /*
                 * Extensible structured sets use PostgreSQL jsonb. Values that
                 * require direct constraints remain scalar columns.
                 */
                $table->jsonb('technology_stack')->default(
                    DB::raw(
                        <<<'SQL'
                        '{"languages":[],"frameworks":[],"databases":[],"infrastructure":[],"package_managers":[],"runtimes":[]}'::jsonb
                        SQL,
                    ),
                );

                $table->string('repository_provider', 32)->nullable();
                $table->string('repository_url', 2048)->nullable();
                $table->string('default_branch', 255)->nullable();
                $table->string('integration_branch', 255)
                    ->default('develop');

                $table->text('build_command')->nullable();
                $table->text('test_command')->nullable();
                $table->text('lint_command')->nullable();
                $table->text('static_analysis_command')->nullable();
                $table->text('security_command')->nullable();

                $table->jsonb('required_documents')->default(
                    DB::raw(
                        <<<'SQL'
                        '[]'::jsonb
                        SQL,
                    ),
                );

                $table->string('default_reasoning', 16)
                    ->default('medium');

                $table->jsonb('provider_policy')->default(
                    DB::raw(
                        <<<'SQL'
                        '{"allowed_provider_ids":[],"fallback_order":[]}'::jsonb
                        SQL,
                    ),
                );

                /*
                 * Money is stored in minor units to avoid floating-point
                 * rounding. For example, USD 50.00 is stored as 5000.
                 */
                $table->unsignedBigInteger('budget_limit_minor')->nullable();
                $table->string('budget_currency', 3)->default('USD');

                $table->unsignedSmallInteger('automatic_retry_limit')
                    ->default(3);

                $table->string('autonomy_level', 32)
                    ->default('approval_required');

                $table->jsonb('approval_policy')->default(
                    DB::raw(
                        <<<'SQL'
                        '{"roadmap_required":true,"ticket_execution_required":true,"merge_required":true}'::jsonb
                        SQL,
                    ),
                );

                $table->jsonb('notification_policy')->default(
                    DB::raw(
                        <<<'SQL'
                        '{"channels":["in_app"],"events":[]}'::jsonb
                        SQL,
                    ),
                );

                $table->timestamps();

                /*
                 * Exactly one current configuration aggregate is allowed for
                 * each project.
                 */
                $table->unique(
                    'project_id',
                    'project_configurations_project_unique',
                );
            },
        );

        /*
         * These constraints protect writes that bypass Eloquent, including
         * imports, queue workers, console commands, and maintenance SQL.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE project_configurations
                ADD CONSTRAINT project_configurations_schema_version_check
                    CHECK (schema_version >= 1),
                ADD CONSTRAINT project_configurations_revision_check
                    CHECK (revision >= 1),
                ADD CONSTRAINT project_configurations_repository_provider_check
                    CHECK (
                        repository_provider IS NULL
                        OR repository_provider IN ('github')
                    ),
                ADD CONSTRAINT project_configurations_integration_branch_check
                    CHECK (
                        char_length(btrim(integration_branch))
                        BETWEEN 1 AND 255
                    ),
                ADD CONSTRAINT project_configurations_default_reasoning_check
                    CHECK (
                        default_reasoning IN ('low', 'medium', 'high')
                    ),
                ADD CONSTRAINT project_configurations_budget_limit_check
                    CHECK (
                        budget_limit_minor IS NULL
                        OR budget_limit_minor >= 0
                    ),
                ADD CONSTRAINT project_configurations_budget_currency_check
                    CHECK (
                        budget_currency ~ '^[A-Z]{3}$'
                    ),
                ADD CONSTRAINT project_configurations_retry_limit_check
                    CHECK (
                        automatic_retry_limit BETWEEN 0 AND 10
                    ),
                ADD CONSTRAINT project_configurations_autonomy_level_check
                    CHECK (
                        autonomy_level IN (
                            'advisory',
                            'approval_required',
                            'policy_controlled'
                        )
                    ),
                ADD CONSTRAINT project_configurations_technology_stack_shape_check
                    CHECK (
                        jsonb_typeof(technology_stack) = 'object'
                    ),
                ADD CONSTRAINT project_configurations_required_documents_shape_check
                    CHECK (
                        jsonb_typeof(required_documents) = 'array'
                    ),
                ADD CONSTRAINT project_configurations_provider_policy_shape_check
                    CHECK (
                        jsonb_typeof(provider_policy) = 'object'
                    ),
                ADD CONSTRAINT project_configurations_approval_policy_shape_check
                    CHECK (
                        jsonb_typeof(approval_policy) = 'object'
                    ),
                ADD CONSTRAINT project_configurations_notification_policy_shape_check
                    CHECK (
                        jsonb_typeof(notification_policy) = 'object'
                    )
            SQL);

        /*
         * Backfill projects created before AIOS-021.
         *
         * ON CONFLICT makes the data migration restartable if deployment is
         * interrupted and safely retried.
         */
        DB::statement(<<<'SQL'
            INSERT INTO project_configurations (
                project_id,
                created_at,
                updated_at
            )
            SELECT
                projects.id,
                NOW(),
                NOW()
            FROM projects
            ON CONFLICT (project_id) DO NOTHING
            SQL);
    }

    /**
     * Remove project configuration records and attached constraints.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_configurations');
    }
};
