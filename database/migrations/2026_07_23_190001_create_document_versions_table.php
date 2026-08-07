<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedBigInteger('version');
            $table->string('original_filename', 255);
            $table->string('media_type', 127);
            $table->unsignedBigInteger('byte_size');
            $table->string('storage_disk', 64);
            $table->string('storage_path', 1_024);
            $table->char('checksum_sha256', 64);
            $table->string('status', 32)->default('uploaded');
            $table->string('classification', 32)->default('unclassified');
            $table->string('parser_name', 120)->nullable();
            $table->string('parser_version', 120)->nullable();
            $table->timestampTz('parsing_started_at')->nullable();
            $table->timestampTz('parsed_at')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message', 512)->nullable();
            $table->foreignId('supersedes_document_version_id')->nullable()->constrained('document_versions')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['document_id', 'version'], 'document_versions_document_version_unique');
            $table->index(['status', 'created_at']);
            $table->index(['classification', 'created_at']);
            $table->index('checksum_sha256');
            $table->index('supersedes_document_version_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE document_versions
                ADD CONSTRAINT document_versions_version_check CHECK (version >= 1),
                ADD CONSTRAINT document_versions_filename_check CHECK (char_length(btrim(original_filename)) BETWEEN 1 AND 255),
                ADD CONSTRAINT document_versions_media_type_check CHECK (char_length(btrim(media_type)) BETWEEN 1 AND 127),
                ADD CONSTRAINT document_versions_byte_size_check CHECK (byte_size >= 1),
                ADD CONSTRAINT document_versions_storage_disk_check CHECK (char_length(btrim(storage_disk)) BETWEEN 1 AND 64),
                ADD CONSTRAINT document_versions_storage_path_check CHECK (char_length(btrim(storage_path)) BETWEEN 1 AND 1024),
                ADD CONSTRAINT document_versions_checksum_check CHECK (checksum_sha256 ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT document_versions_status_check CHECK (status IN ('uploaded', 'quarantined', 'scan_pending', 'scan_failed', 'scan_approved', 'parsing', 'parse_failed', 'parsed', 'approved', 'rejected', 'superseded')),
                ADD CONSTRAINT document_versions_classification_check CHECK (classification IN ('unclassified', 'specification', 'architecture', 'roadmap', 'policy', 'operational', 'other')),
                ADD CONSTRAINT document_versions_parser_name_check CHECK (parser_name IS NULL OR char_length(btrim(parser_name)) BETWEEN 1 AND 120),
                ADD CONSTRAINT document_versions_parser_version_check CHECK (parser_version IS NULL OR char_length(btrim(parser_version)) BETWEEN 1 AND 120),
                ADD CONSTRAINT document_versions_failure_code_check CHECK (failure_code IS NULL OR char_length(btrim(failure_code)) BETWEEN 1 AND 64),
                ADD CONSTRAINT document_versions_failure_message_check CHECK (failure_message IS NULL OR char_length(btrim(failure_message)) BETWEEN 1 AND 512),
                ADD CONSTRAINT document_versions_not_self_superseding_check CHECK (supersedes_document_version_id IS NULL OR supersedes_document_version_id <> id)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};
