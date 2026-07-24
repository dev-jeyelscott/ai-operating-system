<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add deterministic analysis lifecycle and provenance metadata.
     *
     * Existing parsed or approved records are moved to a recoverable failure
     * state because their analyzer identity, version, and seed are unknown.
     * No document content, checksum, or version history is deleted.
     */
    public function up(): void
    {
        Schema::table('document_versions', function (Blueprint $table): void {
            $table->string('analyzer_name', 120)->nullable();
            $table->string('analyzer_version', 120)->nullable();
            $table->unsignedBigInteger('analysis_seed')->nullable();
            $table->timestampTz('analysis_started_at')->nullable();
            $table->timestampTz('analysis_completed_at')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE document_versions
                DROP CONSTRAINT IF EXISTS document_versions_status_check
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE document_versions
                ADD CONSTRAINT document_versions_status_check
                    CHECK (
                        status IN (
                            'uploaded',
                            'quarantined',
                            'scan_pending',
                            'scan_failed',
                            'scan_approved',
                            'parsing',
                            'parse_failed',
                            'parsed',
                            'analysis_pending',
                            'analyzing',
                            'analysis_failed',
                            'needs_review',
                            'approved',
                            'rejected',
                            'superseded'
                        )
                    ),
                ADD CONSTRAINT document_versions_analyzer_name_check
                    CHECK (
                        analyzer_name IS NULL
                        OR char_length(btrim(analyzer_name)) BETWEEN 1 AND 120
                    ),
                ADD CONSTRAINT document_versions_analyzer_version_check
                    CHECK (
                        analyzer_version IS NULL
                        OR char_length(btrim(analyzer_version)) BETWEEN 1 AND 120
                    ),
                ADD CONSTRAINT document_versions_analysis_seed_check
                    CHECK (
                        analysis_seed IS NULL
                        OR analysis_seed >= 0
                    )
            SQL);

        /*
         * Previously approved records cannot retain authority because their
         * deterministic analysis provenance was never recorded.
         */
        DB::table('document_versions')
            ->where('status', 'approved')
            ->update([
                'status' => 'analysis_failed',
                'failure_code' => 'analysis_reprocessing_required_from_approved',
                'failure_message' => 'Analysis provenance is missing. Retry analysis and review this version again.',
                'analysis_started_at' => null,
                'analysis_completed_at' => null,
                'updated_at' => now(),
            ]);

        /*
         * Parsed records similarly require analysis before becoming reviewable.
         */
        DB::table('document_versions')
            ->where('status', 'parsed')
            ->update([
                'status' => 'analysis_failed',
                'failure_code' => 'analysis_reprocessing_required_from_parsed',
                'failure_message' => 'Analysis provenance is missing. Retry analysis before review.',
                'analysis_started_at' => null,
                'analysis_completed_at' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * Collapse analysis lifecycle states back to the former parsed state.
     *
     * Records invalidated from an approved state are restored to approved when
     * their migration-specific failure code remains unchanged.
     */
    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE document_versions
                DROP CONSTRAINT IF EXISTS document_versions_status_check
            SQL);

        DB::table('document_versions')
            ->where('status', 'analysis_failed')
            ->where(
                'failure_code',
                'analysis_reprocessing_required_from_approved',
            )
            ->update([
                'status' => 'approved',
                'failure_code' => null,
                'failure_message' => null,
                'updated_at' => now(),
            ]);

        DB::table('document_versions')
            ->where('status', 'analysis_failed')
            ->where(
                'failure_code',
                'analysis_reprocessing_required_from_parsed',
            )
            ->update([
                'status' => 'parsed',
                'failure_code' => null,
                'failure_message' => null,
                'updated_at' => now(),
            ]);

        DB::table('document_versions')
            ->whereIn('status', [
                'analysis_pending',
                'analyzing',
                'analysis_failed',
                'needs_review',
            ])
            ->update([
                'status' => 'parsed',
                'failure_code' => null,
                'failure_message' => null,
                'updated_at' => now(),
            ]);

        DB::statement(<<<'SQL'
            ALTER TABLE document_versions
                ADD CONSTRAINT document_versions_status_check
                    CHECK (
                        status IN (
                            'uploaded',
                            'quarantined',
                            'scan_pending',
                            'scan_failed',
                            'scan_approved',
                            'parsing',
                            'parse_failed',
                            'parsed',
                            'approved',
                            'rejected',
                            'superseded'
                        )
                    ),
                DROP CONSTRAINT IF EXISTS document_versions_analyzer_name_check,
                DROP CONSTRAINT IF EXISTS document_versions_analyzer_version_check,
                DROP CONSTRAINT IF EXISTS document_versions_analysis_seed_check
            SQL);

        Schema::table('document_versions', function (Blueprint $table): void {
            $table->dropColumn([
                'analyzer_name',
                'analyzer_version',
                'analysis_seed',
                'analysis_started_at',
                'analysis_completed_at',
            ]);
        });
    }
};
