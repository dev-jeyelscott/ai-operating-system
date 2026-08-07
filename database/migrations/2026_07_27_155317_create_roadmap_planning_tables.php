<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('project_context_snapshots', function (Blueprint $table) {
            $table->unique(['project_id', 'id'], 'context_snapshots_project_id_id_unique');
        });
        Schema::table('document_versions', function (Blueprint $table) {
            $table->unique(['document_id', 'id'], 'document_versions_document_id_id_unique');
            $table->unique(['document_id', 'id', 'checksum_sha256'], 'document_versions_document_id_checksum_unique');
        });

        Schema::create('roadmaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('planning_execution_id')->constrained('executions')->cascadeOnDelete();
            $table->foreignId('project_context_snapshot_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_roadmap_id')->nullable()->constrained('roadmaps')->restrictOnDelete();
            $table->foreignUlid('approval_id')->nullable()->constrained('approvals')->restrictOnDelete();
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->unsignedInteger('revision');
            $table->unsignedInteger('content_version')->default(1);
            $table->string('provider_id', 100);
            $table->string('scenario', 100)->nullable();
            $table->unsignedBigInteger('seed')->nullable();
            $table->string('input_fingerprint', 64);
            $table->string('output_fingerprint', 64);
            $table->string('candidate_fingerprint', 64);
            $table->string('approved_fingerprint', 64)->nullable();
            $table->string('status', 40)->default('generated');
            $table->string('readiness', 40);
            $table->text('goal');
            $table->jsonb('scope');
            $table->jsonb('assumptions');
            $table->jsonb('constraints');
            $table->jsonb('definition_of_done');
            $table->jsonb('required_approvals');
            $table->jsonb('document_inventory');
            $table->text('document_summary');
            $table->jsonb('architecture_concerns');
            $table->jsonb('security_concerns');
            $table->jsonb('readiness_reasons');
            $table->jsonb('metadata')->nullable();
            $table->jsonb('derived_graph')->nullable();
            $table->jsonb('generated_snapshot');
            $table->jsonb('approved_snapshot')->nullable();
            $table->text('regeneration_feedback')->nullable();
            $table->string('feedback_fingerprint', 64)->nullable();
            $table->timestampTz('generated_at');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();

            $table->unique(['project_id', 'revision']);
            $table->unique(['planning_execution_id', 'input_fingerprint']);
            $table->unique(['id', 'project_context_snapshot_id'], 'roadmaps_id_context_snapshot_unique');
            $table->unique(['project_id', 'id'], 'roadmaps_project_id_id_unique');
            $table->index(['project_id', 'readiness']);
        });

        Schema::create('roadmap_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roadmap_id')->constrained()->cascadeOnDelete();
            $table->string('stable_id', 100);
            $table->string('name');
            $table->unsignedInteger('position');
            $table->timestamps();
            $table->unique(['roadmap_id', 'stable_id']);
            $table->unique(['roadmap_id', 'position']);
            $table->unique(['roadmap_id', 'id'], 'roadmap_phases_roadmap_id_id_unique');
        });

        Schema::create('roadmap_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roadmap_id')->constrained()->cascadeOnDelete();
            $table->foreignId('roadmap_phase_id')->constrained()->cascadeOnDelete();
            $table->string('stable_id', 100);
            $table->string('name');
            $table->unsignedInteger('position');
            $table->timestamps();
            $table->unique(['roadmap_id', 'stable_id']);
            $table->unique(['roadmap_id', 'id'], 'roadmap_milestones_roadmap_id_id_unique');
            $table->unique(
                ['roadmap_id', 'roadmap_phase_id', 'id'],
                'roadmap_milestones_roadmap_phase_id_id_unique',
            );
        });

        Schema::create('roadmap_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roadmap_id')->constrained()->cascadeOnDelete();
            $table->foreignId('roadmap_phase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('roadmap_milestone_id')->constrained()->cascadeOnDelete();
            $table->string('stable_id', 100);
            $table->string('title');
            $table->text('objective');
            $table->string('ticket_type', 40);
            $table->jsonb('scope');
            $table->jsonb('acceptance_criteria');
            $table->jsonb('source_references');
            $table->jsonb('evidence_requirements');
            $table->string('priority', 40);
            $table->string('risk', 40);
            $table->string('reasoning_level', 40);
            $table->text('reasoning');
            $table->string('logical_agent', 100);
            $table->unsignedInteger('estimated_complexity');
            $table->boolean('human_approval_required')->default(false);
            $table->unsignedInteger('position');
            $table->unsignedInteger('critical_path_rank')->nullable();
            $table->unsignedInteger('critical_path_position')->nullable();
            $table->boolean('is_critical_path')->default(false);
            $table->timestampsTz();
            $table->unique(['roadmap_id', 'stable_id']);
            $table->unique(['roadmap_id', 'id'], 'roadmap_tasks_roadmap_id_id_unique');
        });

        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roadmap_id')->constrained()->cascadeOnDelete();
            $table->foreignId('roadmap_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('depends_on_task_id')->constrained('roadmap_tasks')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['roadmap_task_id', 'depends_on_task_id']);
        });

        Schema::create('roadmap_traceability_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roadmap_id')->constrained()->cascadeOnDelete();
            $table->foreignId('roadmap_task_id')->constrained()->cascadeOnDelete();
            $table->string('criterion_stable_id', 100);
            $table->foreignId('project_context_snapshot_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_version_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('document_version');
            $table->string('checksum_sha256', 64);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(
                ['roadmap_task_id', 'criterion_stable_id', 'document_version_id'],
                'roadmap_traceability_task_criterion_document_unique',
            );
            $table->index(['document_version_id', 'roadmap_id']);
        });

        Schema::create('roadmap_edits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roadmap_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('base_content_version');
            $table->unsignedInteger('content_version');
            $table->jsonb('patch');
            $table->string('resulting_fingerprint', 64);
            $table->string('idempotency_key_hash', 64);
            $table->string('request_fingerprint', 64);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['roadmap_id', 'content_version']);
            $table->unique(['roadmap_id', 'idempotency_key_hash'], 'roadmap_edits_roadmap_idempotency_unique');
        });

        Schema::create('planning_execution_diagnostics', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('execution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('execution_attempt_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code', 100);
            $table->string('category', 40);
            $table->text('message');
            $table->jsonb('details');
            $table->string('fingerprint', 64);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['execution_id', 'fingerprint']);
        });

        Schema::create('external_ticket_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roadmap_task_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('external_key', 191);
            $table->string('page_id', 191)->nullable();
            $table->text('page_url')->nullable();
            $table->string('last_synchronized_fingerprint', 64)->nullable();
            $table->string('state', 40)->default('pending');
            $table->json('failure_metadata')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'external_key']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE roadmaps
                ADD CONSTRAINT roadmaps_project_execution_consistency_foreign
                    FOREIGN KEY (planning_execution_id, project_id)
                    REFERENCES executions (id, project_id) ON DELETE CASCADE,
                ADD CONSTRAINT roadmaps_project_context_consistency_foreign
                    FOREIGN KEY (project_id, project_context_snapshot_id)
                    REFERENCES project_context_snapshots (project_id, id) ON DELETE RESTRICT,
                ADD CONSTRAINT roadmaps_parent_project_consistency_foreign
                    FOREIGN KEY (project_id, parent_roadmap_id)
                    REFERENCES roadmaps (project_id, id) ON DELETE RESTRICT,
                ADD CONSTRAINT roadmaps_schema_version_check CHECK (schema_version >= 1),
                ADD CONSTRAINT roadmaps_revision_check CHECK (revision >= 1),
                ADD CONSTRAINT roadmaps_content_version_check CHECK (content_version >= 1),
                ADD CONSTRAINT roadmaps_readiness_check CHECK (readiness IN ('ready', 'ready_with_risks', 'blocked', 'human_decision_required')),
                ADD CONSTRAINT roadmaps_status_check CHECK (status IN ('generated', 'awaiting_approval', 'approved', 'rejected', 'superseded', 'blocked')),
                ADD CONSTRAINT roadmaps_fingerprint_check CHECK (
                    input_fingerprint ~ '^[0-9a-f]{64}$'
                    AND output_fingerprint ~ '^[0-9a-f]{64}$'
                    AND candidate_fingerprint ~ '^[0-9a-f]{64}$'
                    AND (approved_fingerprint IS NULL OR approved_fingerprint ~ '^[0-9a-f]{64}$')
                    AND (feedback_fingerprint IS NULL OR feedback_fingerprint ~ '^[0-9a-f]{64}$')
                ),
                ADD CONSTRAINT roadmaps_json_shapes_check CHECK (
                    jsonb_typeof(scope) = 'array'
                    AND jsonb_typeof(assumptions) = 'array'
                    AND jsonb_typeof(constraints) = 'array'
                    AND jsonb_typeof(definition_of_done) = 'array'
                    AND jsonb_typeof(required_approvals) = 'array'
                    AND jsonb_typeof(document_inventory) = 'array'
                    AND jsonb_typeof(architecture_concerns) = 'array'
                    AND jsonb_typeof(security_concerns) = 'array'
                    AND jsonb_typeof(readiness_reasons) = 'array'
                    AND jsonb_typeof(generated_snapshot) = 'object'
                    AND (approved_snapshot IS NULL OR jsonb_typeof(approved_snapshot) = 'object')
                )
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE roadmap_tasks
                ADD CONSTRAINT roadmap_tasks_ticket_type_check CHECK (ticket_type IN ('feature', 'bug', 'enhancement', 'change', 'technical_debt')),
                ADD CONSTRAINT roadmap_tasks_priority_check CHECK (priority IN ('low', 'medium', 'high', 'critical')),
                ADD CONSTRAINT roadmap_tasks_risk_check CHECK (risk IN ('low', 'medium', 'high', 'critical')),
                ADD CONSTRAINT roadmap_tasks_reasoning_level_check CHECK (reasoning_level IN ('low', 'medium', 'high')),
                ADD CONSTRAINT roadmap_tasks_complexity_check CHECK (estimated_complexity BETWEEN 1 AND 13),
                ADD CONSTRAINT roadmap_tasks_json_shapes_check CHECK (
                    jsonb_typeof(scope) = 'object'
                    AND jsonb_typeof(acceptance_criteria) = 'array'
                    AND jsonb_typeof(source_references) = 'array'
                    AND jsonb_typeof(evidence_requirements) = 'array'
                ),
                ADD CONSTRAINT roadmap_tasks_phase_consistency_foreign
                    FOREIGN KEY (roadmap_id, roadmap_phase_id)
                    REFERENCES roadmap_phases (roadmap_id, id) ON DELETE CASCADE,
                ADD CONSTRAINT roadmap_tasks_milestone_consistency_foreign
                    FOREIGN KEY (roadmap_id, roadmap_phase_id, roadmap_milestone_id)
                    REFERENCES roadmap_milestones (roadmap_id, roadmap_phase_id, id) ON DELETE CASCADE
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE task_dependencies
                ADD CONSTRAINT task_dependencies_task_roadmap_foreign
                    FOREIGN KEY (roadmap_id, roadmap_task_id)
                    REFERENCES roadmap_tasks (roadmap_id, id) ON DELETE CASCADE,
                ADD CONSTRAINT task_dependencies_depends_on_roadmap_foreign
                    FOREIGN KEY (roadmap_id, depends_on_task_id)
                    REFERENCES roadmap_tasks (roadmap_id, id) ON DELETE CASCADE,
                ADD CONSTRAINT task_dependencies_not_self_check
                    CHECK (roadmap_task_id <> depends_on_task_id)
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE roadmap_traceability_links
                ADD CONSTRAINT roadmap_traceability_task_roadmap_foreign
                    FOREIGN KEY (roadmap_id, roadmap_task_id)
                    REFERENCES roadmap_tasks (roadmap_id, id) ON DELETE CASCADE,
                ADD CONSTRAINT roadmap_traceability_context_roadmap_foreign
                    FOREIGN KEY (roadmap_id, project_context_snapshot_id)
                    REFERENCES roadmaps (id, project_context_snapshot_id) ON DELETE CASCADE,
                ADD CONSTRAINT roadmap_traceability_document_version_foreign
                    FOREIGN KEY (document_id, document_version_id, checksum_sha256)
                    REFERENCES document_versions (document_id, id, checksum_sha256) ON DELETE RESTRICT,
                ADD CONSTRAINT roadmap_traceability_checksum_check
                    CHECK (checksum_sha256 ~ '^[0-9a-f]{64}$')
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE roadmap_edits
                ADD CONSTRAINT roadmap_edits_versions_check
                    CHECK (base_content_version >= 1 AND content_version = base_content_version + 1),
                ADD CONSTRAINT roadmap_edits_patch_shape_check
                    CHECK (jsonb_typeof(patch) = 'object'),
                ADD CONSTRAINT roadmap_edits_fingerprints_check
                    CHECK (
                        resulting_fingerprint ~ '^[0-9a-f]{64}$'
                        AND idempotency_key_hash ~ '^[0-9a-f]{64}$'
                        AND request_fingerprint ~ '^[0-9a-f]{64}$'
                    )
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.aios_reject_roadmap_edit_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION USING
                    MESSAGE = 'roadmap_edits is append-only',
                    ERRCODE = '55000';
            END;
            $$;

            CREATE TRIGGER roadmap_edits_reject_update_delete
            BEFORE UPDATE OR DELETE ON roadmap_edits
            FOR EACH ROW
            EXECUTE FUNCTION public.aios_reject_roadmap_edit_mutation();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.aios_reject_generated_roadmap_content_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION USING
                    MESSAGE = 'provider-generated roadmap content is immutable',
                    ERRCODE = '55000';
            END;
            $$;

            CREATE TRIGGER roadmaps_reject_delete
            BEFORE DELETE ON roadmaps
            FOR EACH ROW EXECUTE FUNCTION public.aios_reject_generated_roadmap_content_mutation();

            CREATE TRIGGER roadmaps_reject_generated_update
            BEFORE UPDATE OF project_id, planning_execution_id, project_context_snapshot_id,
                parent_roadmap_id, schema_version, revision, provider_id, scenario, seed,
                input_fingerprint, output_fingerprint, goal, scope, assumptions, constraints,
                definition_of_done, required_approvals, document_inventory, document_summary,
                architecture_concerns, security_concerns, derived_graph, generated_snapshot,
                generated_at
            ON roadmaps FOR EACH ROW
            EXECUTE FUNCTION public.aios_reject_generated_roadmap_content_mutation();

            CREATE TRIGGER roadmap_phases_reject_mutation
            BEFORE UPDATE OR DELETE ON roadmap_phases FOR EACH ROW
            EXECUTE FUNCTION public.aios_reject_generated_roadmap_content_mutation();
            CREATE TRIGGER roadmap_milestones_reject_mutation
            BEFORE UPDATE OR DELETE ON roadmap_milestones FOR EACH ROW
            EXECUTE FUNCTION public.aios_reject_generated_roadmap_content_mutation();
            CREATE TRIGGER roadmap_tasks_reject_mutation
            BEFORE UPDATE OR DELETE ON roadmap_tasks FOR EACH ROW
            EXECUTE FUNCTION public.aios_reject_generated_roadmap_content_mutation();
            CREATE TRIGGER task_dependencies_reject_mutation
            BEFORE UPDATE OR DELETE ON task_dependencies FOR EACH ROW
            EXECUTE FUNCTION public.aios_reject_generated_roadmap_content_mutation();
            CREATE TRIGGER roadmap_traceability_reject_mutation
            BEFORE UPDATE OR DELETE ON roadmap_traceability_links FOR EACH ROW
            EXECUTE FUNCTION public.aios_reject_generated_roadmap_content_mutation();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_ticket_mappings');
        Schema::dropIfExists('planning_execution_diagnostics');
        Schema::dropIfExists('roadmap_edits');
        Schema::dropIfExists('roadmap_traceability_links');
        Schema::dropIfExists('task_dependencies');
        Schema::dropIfExists('roadmap_tasks');
        Schema::dropIfExists('roadmap_milestones');
        Schema::dropIfExists('roadmap_phases');
        Schema::dropIfExists('roadmaps');
        DB::unprepared('DROP FUNCTION IF EXISTS public.aios_reject_roadmap_edit_mutation()');
        DB::unprepared('DROP FUNCTION IF EXISTS public.aios_reject_generated_roadmap_content_mutation()');
        Schema::table('document_versions', function (Blueprint $table) {
            $table->dropUnique('document_versions_document_id_checksum_unique');
            $table->dropUnique('document_versions_document_id_id_unique');
        });
        Schema::table('project_context_snapshots', function (Blueprint $table) {
            $table->dropUnique('context_snapshots_project_id_id_unique');
        });
    }
};
