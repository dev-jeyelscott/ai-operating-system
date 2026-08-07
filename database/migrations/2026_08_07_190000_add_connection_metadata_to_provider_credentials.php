<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add nullable, non-secret preflight metadata without rewriting ciphertext.
     */
    public function up(): void
    {
        Schema::table('provider_credentials', function (Blueprint $table): void {
            $table->string('last_connection_status', 32)->nullable();
            $table->string('last_connection_failure_code', 100)->nullable();
            $table->string('last_provider_request_id', 255)->nullable();
            $table->foreignId('last_tested_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->unsignedInteger('verified_credential_version')->nullable();
            $table->timestampTz('last_tested_at')->nullable();
            $table->timestampTz('last_connected_at')->nullable();
        });
    }

    /**
     * Refuse destructive rollback because connection/audit provenance may exist.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'AIOS-243 provider credential metadata is forward-only; rollback the application behavior without dropping security provenance.',
        );
    }
};
