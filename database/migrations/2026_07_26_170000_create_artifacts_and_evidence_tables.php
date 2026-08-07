<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create append-only artifact and evidence history with tenant-safe lineage.
     */
    public function up(): void
    {
        /*
         * The composite key lets PostgreSQL prove that an artifact attempt belongs
         * to the same logical execution, even when a write bypasses Eloquent.
         */
        Schema::table('execution_attempts', function (Blueprint $table): void {
            $table->unique(
                ['id', 'execution_id'],
                'execution_attempts_id_execution_unique',
            );
        });

        Schema::create('artifacts', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignId('project_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->ulid('execution_id');
            $table->unsignedBigInteger('execution_attempt_id');

            $table->string('artifact_type', 80);
            $table->string('name', 191);
            $table->string('execution_provider', 120);

            /*
             * An artifact references either object storage or an immutable external
             * source. Artifact payloads and secrets do not belong in this table.
             */
            $table->string('storage_disk', 80)->nullable();
            $table->string('storage_path', 1024)->nullable();
            $table->text('external_reference')->nullable();
            $table->string('media_type', 191)->nullable();
            $table->char('checksum_sha256', 64)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();

            /*
             * Simulation provenance is copied from the immutable attempt snapshot
             * so artifact exports remain non-deceptive when viewed independently.
             */
            $table->string('simulation_mode', 120)->nullable();
            $table->string('simulation_seed', 191)->nullable();
            $table->jsonb('assumptions');

            $table->decimal(
                'confidence',
                total: 5,
                places: 4,
            )->nullable();

            $table->boolean('evidence_still_required')->default(true);
            $table->string('actual_state', 120)->default('unverified');
            $table->jsonb('metadata');

            /*
             * Artifacts are immutable history and therefore have no updated_at.
             */
            $table->timestampTz('created_at', precision: 6)->useCurrent();

            $table->index(
                ['project_id', 'created_at'],
                'artifacts_project_created_index',
            );

            $table->index(
                ['execution_id', 'created_at'],
                'artifacts_execution_created_index',
            );

            $table->index(
                ['execution_attempt_id', 'created_at'],
                'artifacts_attempt_created_index',
            );

            $table->index(
                ['artifact_type', 'created_at'],
                'artifacts_type_created_index',
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE artifacts
                ADD CONSTRAINT artifacts_execution_project_foreign
                    FOREIGN KEY (
                        execution_id,
                        project_id
                    )
                    REFERENCES executions (
                        id,
                        project_id
                    )
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT,
                ADD CONSTRAINT artifacts_attempt_execution_foreign
                    FOREIGN KEY (
                        execution_attempt_id,
                        execution_id
                    )
                    REFERENCES execution_attempts (
                        id,
                        execution_id
                    )
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE artifacts
                ADD CONSTRAINT artifacts_type_check
                    CHECK (
                        artifact_type
                            ~ '^[a-z][a-z0-9_.-]{1,79}$'
                    ),
                ADD CONSTRAINT artifacts_name_check
                    CHECK (btrim(name) <> ''),
                ADD CONSTRAINT artifacts_provider_check
                    CHECK (
                        execution_provider
                            ~ '^[a-z][a-z0-9_.-]{1,119}$'
                    ),
                ADD CONSTRAINT artifacts_location_check
                    CHECK (
                        (
                            storage_disk IS NOT NULL
                            AND storage_path IS NOT NULL
                            AND external_reference IS NULL
                        )
                        OR (
                            storage_disk IS NULL
                            AND storage_path IS NULL
                            AND external_reference IS NOT NULL
                            AND btrim(external_reference) <> ''
                        )
                    ),
                ADD CONSTRAINT artifacts_checksum_check
                    CHECK (
                        checksum_sha256 IS NULL
                        OR checksum_sha256 ~ '^[a-f0-9]{64}$'
                    ),
                ADD CONSTRAINT artifacts_byte_size_check
                    CHECK (
                        byte_size IS NULL
                        OR byte_size >= 0
                    ),
                ADD CONSTRAINT artifacts_assumptions_shape_check
                    CHECK (jsonb_typeof(assumptions) = 'array'),
                ADD CONSTRAINT artifacts_metadata_shape_check
                    CHECK (jsonb_typeof(metadata) = 'object'),
                ADD CONSTRAINT artifacts_confidence_check
                    CHECK (
                        confidence IS NULL
                        OR (
                            confidence >= 0
                            AND confidence <= 1
                        )
                    ),
                ADD CONSTRAINT artifacts_actual_state_check
                    CHECK (
                        actual_state
                            ~ '^[a-z][a-z0-9_]{1,119}$'
                    ),
                ADD CONSTRAINT artifacts_simulation_consistency_check
                    CHECK (
                        (
                            execution_provider = 'simulation'
                            AND simulation_mode IS NOT NULL
                            AND simulation_seed IS NOT NULL
                            AND actual_state = 'unverified'
                            AND evidence_still_required = true
                        )
                        OR (
                            execution_provider <> 'simulation'
                            AND simulation_mode IS NULL
                            AND simulation_seed IS NULL
                        )
                    )
            SQL);

        Schema::create('evidence', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('artifact_id')
                ->constrained('artifacts')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('classification', 40);
            $table->string('evidence_type', 80);
            $table->string('provider', 120);
            $table->text('source_reference');
            $table->string('commit_sha', 64)->nullable();
            $table->jsonb('claims');

            $table->text('verification_method')->nullable();
            $table->timestampTz('observed_at', precision: 6)->nullable();
            $table->timestampTz('verified_at', precision: 6)->nullable();
            $table->timestampTz('expires_at', precision: 6)->nullable();
            $table->text('rejection_reason')->nullable();

            $table->decimal(
                'confidence',
                total: 5,
                places: 4,
            )->nullable();

            $table->jsonb('metadata');

            /*
             * Evidence records are immutable classifications, not mutable status
             * rows. A later observation or verification creates another record.
             */
            $table->timestampTz('created_at', precision: 6)->useCurrent();

            $table->index(
                ['artifact_id', 'created_at'],
                'evidence_artifact_created_index',
            );

            $table->index(
                ['classification', 'created_at'],
                'evidence_classification_created_index',
            );

            $table->index(
                ['evidence_type', 'created_at'],
                'evidence_type_created_index',
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE evidence
                ADD CONSTRAINT evidence_classification_check
                    CHECK (
                        classification IN (
                            'assumption',
                            'proposal',
                            'simulated_output',
                            'reported_evidence',
                            'observed_evidence',
                            'verified_evidence',
                            'rejected_evidence'
                        )
                    ),
                ADD CONSTRAINT evidence_type_check
                    CHECK (
                        evidence_type
                            ~ '^[a-z][a-z0-9_.-]{1,79}$'
                    ),
                ADD CONSTRAINT evidence_provider_check
                    CHECK (
                        provider
                            ~ '^[a-z][a-z0-9_.-]{1,119}$'
                    ),
                ADD CONSTRAINT evidence_source_reference_check
                    CHECK (btrim(source_reference) <> ''),
                ADD CONSTRAINT evidence_commit_sha_check
                    CHECK (
                        commit_sha IS NULL
                        OR commit_sha ~ '^(?:[a-f0-9]{40}|[a-f0-9]{64})$'
                    ),
                ADD CONSTRAINT evidence_claims_shape_check
                    CHECK (
                        jsonb_typeof(claims) = 'array'
                        AND jsonb_array_length(claims) >= 1
                    ),
                ADD CONSTRAINT evidence_metadata_shape_check
                    CHECK (jsonb_typeof(metadata) = 'object'),
                ADD CONSTRAINT evidence_confidence_check
                    CHECK (
                        confidence IS NULL
                        OR (
                            confidence >= 0
                            AND confidence <= 1
                        )
                    ),
                ADD CONSTRAINT evidence_lifecycle_check
                    CHECK (
                        (
                            classification = 'verified_evidence'
                            AND observed_at IS NOT NULL
                            AND verified_at IS NOT NULL
                            AND verification_method IS NOT NULL
                            AND btrim(verification_method) <> ''
                            AND rejection_reason IS NULL
                        )
                        OR (
                            classification = 'observed_evidence'
                            AND observed_at IS NOT NULL
                            AND verified_at IS NULL
                            AND verification_method IS NULL
                            AND rejection_reason IS NULL
                        )
                        OR (
                            classification = 'rejected_evidence'
                            AND observed_at IS NOT NULL
                            AND verified_at IS NULL
                            AND verification_method IS NOT NULL
                            AND btrim(verification_method) <> ''
                            AND rejection_reason IS NOT NULL
                            AND btrim(rejection_reason) <> ''
                        )
                        OR (
                            classification IN (
                                'assumption',
                                'proposal',
                                'simulated_output',
                                'reported_evidence'
                            )
                            AND observed_at IS NULL
                            AND verified_at IS NULL
                            AND verification_method IS NULL
                            AND rejection_reason IS NULL
                        )
                    ),
                ADD CONSTRAINT evidence_time_order_check
                    CHECK (
                        verified_at IS NULL
                        OR verified_at >= observed_at
                    ),
                ADD CONSTRAINT evidence_expiration_check
                    CHECK (
                        expires_at IS NULL
                        OR expires_at >= COALESCE(
                            verified_at,
                            observed_at,
                            created_at
                        )
                    )
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION
                public.aios_validate_artifact_provenance()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                attempt_provider text;
                attempt_simulation_mode text;
                attempt_simulation_seed text;
            BEGIN
                SELECT
                    execution_provider,
                    simulation_mode,
                    simulation_seed
                INTO
                    attempt_provider,
                    attempt_simulation_mode,
                    attempt_simulation_seed
                FROM execution_attempts
                WHERE id = NEW.execution_attempt_id
                  AND execution_id = NEW.execution_id;

                IF attempt_provider IS NULL THEN
                    RAISE EXCEPTION USING
                        MESSAGE = 'artifact execution attempt was not found',
                        ERRCODE = '23503';
                END IF;

                IF NEW.execution_provider IS DISTINCT FROM attempt_provider
                   OR NEW.simulation_mode IS DISTINCT FROM attempt_simulation_mode
                   OR NEW.simulation_seed IS DISTINCT FROM attempt_simulation_seed THEN
                    RAISE EXCEPTION USING
                        MESSAGE = 'artifact provenance must match its execution attempt',
                        ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER artifacts_validate_provenance
            BEFORE INSERT
                ON artifacts
            FOR EACH ROW
            EXECUTE FUNCTION aios_validate_artifact_provenance()
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION
                public.aios_validate_evidence_classification()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                artifact_provider text;
            BEGIN
                IF NEW.classification = 'verified_evidence' THEN
                    SELECT execution_provider
                    INTO artifact_provider
                    FROM artifacts
                    WHERE id = NEW.artifact_id;

                    IF artifact_provider = 'simulation'
                       OR NEW.provider = 'simulation' THEN
                        RAISE EXCEPTION USING
                            MESSAGE = 'simulation cannot produce or verify verified evidence',
                            ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER evidence_validate_classification
            BEFORE INSERT
                ON evidence
            FOR EACH ROW
            EXECUTE FUNCTION aios_validate_evidence_classification()
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION
                public.aios_reject_artifact_evidence_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION USING
                    MESSAGE = TG_TABLE_NAME || ' is append-only',
                    ERRCODE = '55000';
            END;
            $$;
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER artifacts_reject_update_delete
            BEFORE UPDATE OR DELETE
                ON artifacts
            FOR EACH ROW
            EXECUTE FUNCTION aios_reject_artifact_evidence_mutation()
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER evidence_reject_update_delete
            BEFORE UPDATE OR DELETE
                ON evidence
            FOR EACH ROW
            EXECUTE FUNCTION aios_reject_artifact_evidence_mutation()
            SQL);
    }

    /**
     * Remove artifact and evidence history during an explicitly approved rollback.
     */
    public function down(): void
    {
        Schema::dropIfExists('evidence');
        Schema::dropIfExists('artifacts');

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS
                public.aios_reject_artifact_evidence_mutation()
            SQL);

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS
                public.aios_validate_evidence_classification()
            SQL);

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS
                public.aios_validate_artifact_provenance()
            SQL);

        Schema::table('execution_attempts', function (Blueprint $table): void {
            $table->dropUnique(
                'execution_attempts_id_execution_unique',
            );
        });
    }
};
