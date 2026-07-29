<?php

declare(strict_types=1);

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
        Schema::table('artifacts', function (Blueprint $table): void {
            $table->string('idempotency_key', 255)->nullable();
            $table->char('content_fingerprint_sha256', 64)->nullable();
        });
        Schema::table('evidence', function (Blueprint $table): void {
            $table->string('provenance_key', 255)->nullable();
            $table->char('content_fingerprint_sha256', 64)->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX artifacts_project_idempotency_unique ON artifacts (project_id, idempotency_key) WHERE idempotency_key IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX evidence_artifact_provenance_unique ON evidence (artifact_id, provenance_key) WHERE provenance_key IS NOT NULL');
        DB::statement("ALTER TABLE artifacts ADD CONSTRAINT artifacts_content_fingerprint_check CHECK (content_fingerprint_sha256 IS NULL OR content_fingerprint_sha256 ~ '^[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE evidence ADD CONSTRAINT evidence_content_fingerprint_check CHECK (content_fingerprint_sha256 IS NULL OR content_fingerprint_sha256 ~ '^[a-f0-9]{64}$')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS evidence_artifact_provenance_unique');
        DB::statement('DROP INDEX IF EXISTS artifacts_project_idempotency_unique');
        Schema::table('evidence', function (Blueprint $table): void {
            $table->dropColumn(['provenance_key', 'content_fingerprint_sha256']);
        });
        Schema::table('artifacts', function (Blueprint $table): void {
            $table->dropColumn(['idempotency_key', 'content_fingerprint_sha256']);
        });
    }
};
